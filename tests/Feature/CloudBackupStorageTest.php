<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, BackupRun, Setting};
use App\Security\SecretCipher;
use App\Services\{BackupQueue, CloudBackupStorageService, ShopBackupService};
use Illuminate\Support\Facades\{DB, Hash, Http, Storage};
use Tests\TestCase;

class CloudBackupStorageTest extends TestCase
{
    private function authenticateOwner(): Admin
    {
        $admin = Admin::create([
            'username' => 'cloud-owner',
            'password' => Hash::make('cloud-owner-password-123'),
            'role' => 'owner',
            'permissions' => [],
            'is_active' => true,
        ]);
        $this->withSession([
            'admin_id' => $admin->id,
            'admin_pw' => AdminAuth::passwordFingerprint($admin->password),
        ]);

        return $admin;
    }

    private function createTempArchive(string $content = 'dummy-archive-payload-for-cloud-backup'): string
    {
        $dir = storage_path('app/private/shop-backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $file = $dir . '/shop-20261125-030000-cloudtest.tar.gz';
        file_put_contents($file, $content);

        return $file;
    }

    protected function tearDown(): void
    {
        $file = storage_path('app/private/shop-backups/shop-20261125-030000-cloudtest.tar.gz');
        if (is_file($file)) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_all_four_providers_build_expected_endpoints_and_cryptographic_signatures(): void
    {
        $service = app(CloudBackupStorageService::class);
        $file = $this->createTempArchive('exact-backup-bytes-123456');
        $expectedSha256 = hash_file('sha256', $file);
        $expectedMd5 = base64_encode((string) md5_file($file, true));

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = [
                'method' => $request->method(),
                'url' => $request->url(),
                'headers' => $request->headers(),
                'body' => $request->body(),
            ];

            return Http::response('', 200);
        });

        // 1. Cloudflare R2 (path-style + AWS4-HMAC-SHA256 + default auto region)
        $r2 = $service->uploadFile('r2', $file, [
            'endpoint' => 'https://acct12345.r2.cloudflarestorage.com',
            'region' => '',
            'bucket' => 'shop-r2-bucket',
            'prefix' => '/daily/prod/',
            'access_key' => 'r2-ak',
            'secret_key' => 'r2-sk',
        ]);
        $this->assertSame('daily/prod/' . basename($file), $r2['key']);

        // 2. AWS S3 (virtual-hosted default + AWS4-HMAC-SHA256)
        $service->uploadFile('s3', $file, [
            'endpoint' => '',
            'region' => 'ap-southeast-1',
            'bucket' => 'shop-s3-bucket',
            'prefix' => '',
            'access_key' => 's3-ak',
            'secret_key' => 's3-sk',
            'path_style' => false,
        ]);

        // 3. AWS S3 with custom endpoint + path-style enabled
        $service->uploadFile('s3', $file, [
            'endpoint' => 'https://minio.example.test',
            'region' => 'us-east-1',
            'bucket' => 'shop-minio',
            'prefix' => 'cards',
            'access_key' => 'minio-ak',
            'secret_key' => 'minio-sk',
            'path_style' => true,
        ]);

        // 4. Alibaba Cloud OSS (virtual-hosted + native OSS4-HMAC-SHA256 + strips oss- prefix)
        $service->uploadFile('oss', $file, [
            'endpoint' => '',
            'region' => 'oss-cn-hangzhou',
            'bucket' => 'shop-oss-bucket',
            'prefix' => 'backups',
            'access_key' => 'oss-ak',
            'secret_key' => 'oss-sk',
        ]);

        // 5. Tencent Cloud COS (virtual-hosted + AWS4-HMAC-SHA256 with bucket-appid)
        $service->uploadFile('cos', $file, [
            'endpoint' => '',
            'region' => 'ap-guangzhou',
            'bucket' => 'shop-1250000000',
            'prefix' => 'cos-dir',
            'access_key' => 'cos-ak',
            'secret_key' => 'cos-sk',
        ]);

        $this->assertCount(5, $captured);

        // Verify R2
        $this->assertSame('PUT', $captured[0]['method']);
        $this->assertSame('https://acct12345.r2.cloudflarestorage.com/shop-r2-bucket/daily/prod/' . basename($file), $captured[0]['url']);
        $this->assertStringContainsString('AWS4-HMAC-SHA256 Credential=r2-ak/', $captured[0]['headers']['Authorization'][0]);
        $this->assertStringContainsString('/auto/s3/aws4_request', $captured[0]['headers']['Authorization'][0]);
        $this->assertSame($expectedSha256, $captured[0]['headers']['X-Amz-Content-Sha256'][0]);
        $this->assertSame($expectedMd5, $captured[0]['headers']['Content-MD5'][0]);
        $this->assertSame('exact-backup-bytes-123456', $captured[0]['body']);

        // Verify AWS S3 virtual-hosted
        $this->assertSame('https://shop-s3-bucket.s3.ap-southeast-1.amazonaws.com/' . basename($file), $captured[1]['url']);
        $this->assertStringContainsString('/ap-southeast-1/s3/aws4_request', $captured[1]['headers']['Authorization'][0]);

        // Verify AWS S3 custom path-style
        $this->assertSame('https://minio.example.test/shop-minio/cards/' . basename($file), $captured[2]['url']);
        $this->assertStringContainsString('/us-east-1/s3/aws4_request', $captured[2]['headers']['Authorization'][0]);

        // Verify Alibaba Cloud OSS native OSS4-HMAC-SHA256
        $this->assertSame('https://shop-oss-bucket.oss-cn-hangzhou.aliyuncs.com/backups/' . basename($file), $captured[3]['url']);
        $this->assertStringContainsString('OSS4-HMAC-SHA256 Credential=oss-ak/', $captured[3]['headers']['Authorization'][0]);
        $this->assertStringContainsString('/cn-hangzhou/oss/aliyun_v4_request, AdditionalHeaders=host, Signature=', $captured[3]['headers']['Authorization'][0]);
        $this->assertSame('UNSIGNED-PAYLOAD', $captured[3]['headers']['x-oss-content-sha256'][0]);
        $this->assertSame($expectedMd5, $captured[3]['headers']['Content-MD5'][0]);

        // Verify Tencent Cloud COS virtual-hosted S3-compatible
        $this->assertSame('https://shop-1250000000.cos.ap-guangzhou.myqcloud.com/cos-dir/' . basename($file), $captured[4]['url']);
        $this->assertStringContainsString('AWS4-HMAC-SHA256 Credential=cos-ak/', $captured[4]['headers']['Authorization'][0]);
        $this->assertStringContainsString('/ap-guangzhou/s3/aws4_request', $captured[4]['headers']['Authorization'][0]);
        $this->assertSame($expectedMd5, $captured[4]['headers']['Content-MD5'][0]);
    }

    public function test_backup_queue_uploads_to_multiple_selected_cloud_providers_simultaneously_and_isolates_failures(): void
    {
        $file = $this->createTempArchive('multi-target-archive-bytes');

        // Enable ALL 4 cloud providers simultaneously.
        Setting::set('backup_r2_enabled', '1', 'backup');
        Setting::set('backup_r2_endpoint', 'https://acct.r2.cloudflarestorage.com', 'backup');
        Setting::set('backup_r2_bucket', 'r2-bucket', 'backup');
        Setting::set('backup_r2_access_key', 'r2-ak', 'backup');
        Setting::set('backup_r2_secret_key', 'r2-sk', 'backup');

        Setting::set('backup_s3_enabled', '1', 'backup');
        Setting::set('backup_s3_region', 'us-east-1', 'backup');
        Setting::set('backup_s3_bucket', 's3-bucket', 'backup');
        Setting::set('backup_s3_access_key', 's3-ak', 'backup');
        Setting::set('backup_s3_secret_key', 's3-sk', 'backup');

        Setting::set('backup_oss_enabled', '1', 'backup');
        Setting::set('backup_oss_region', 'cn-hangzhou', 'backup');
        Setting::set('backup_oss_bucket', 'oss-bucket', 'backup');
        Setting::set('backup_oss_access_key', 'oss-ak', 'backup');
        Setting::set('backup_oss_secret_key', 'oss-sk', 'backup');

        Setting::set('backup_cos_enabled', '1', 'backup');
        Setting::set('backup_cos_region', 'ap-guangzhou', 'backup');
        Setting::set('backup_cos_bucket', 'cos-1250000000', 'backup');
        Setting::set('backup_cos_access_key', 'cos-ak', 'backup');
        Setting::set('backup_cos_secret_key', 'cos-sk', 'backup');

        $uploadedHosts = [];
        Http::fake(function ($request) use (&$uploadedHosts) {
            $uploadedHosts[] = parse_url($request->url(), PHP_URL_HOST);

            return Http::response('', 200);
        });

        $mockBackup = $this->createMock(ShopBackupService::class);
        $mockBackup->expects($this->once())->method('create')->willReturn($file);
        $mockBackup->expects($this->once())->method('validate')->with($file)->willReturn(['version' => 1]);
        $mockBackup->expects($this->once())->method('prune')->with($file);

        $queue = new BackupQueue($mockBackup, app(CloudBackupStorageService::class));
        $run = $queue->enqueue('manual');
        $this->assertSame('pending', $run->sync_status);
        $this->assertTrue($queue->process());

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('synced', $run->sync_status);
        $this->assertNull($run->last_error);
        $this->assertEqualsCanonicalizing([
            'acct.r2.cloudflarestorage.com',
            's3-bucket.s3.us-east-1.amazonaws.com',
            'oss-bucket.oss-cn-hangzhou.aliyuncs.com',
            'cos-1250000000.cos.ap-guangzhou.myqcloud.com',
        ], $uploadedHosts);

        // Partial failure: OSS fails with 500, but S3, R2, and COS STILL complete cleanly.
        $secondHosts = [];
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function ($request) use (&$secondHosts) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $secondHosts[] = $host;
            if (str_contains((string) $host, 'aliyuncs.com')) {
                return Http::response('<Error><Code>InternalError</Code></Error>', 500);
            }

            return Http::response('', 200);
        });

        $mockBackup2 = $this->createMock(ShopBackupService::class);
        $mockBackup2->expects($this->once())->method('create')->willReturn($file);
        $mockBackup2->expects($this->once())->method('validate')->with($file)->willReturn(['version' => 1]);
        $mockBackup2->expects($this->once())->method('prune')->with($file);

        $queue2 = new BackupQueue($mockBackup2, app(CloudBackupStorageService::class));
        $run2 = $queue2->enqueue('manual');
        $this->assertTrue($queue2->process());

        $run2->refresh();
        $this->assertCount(4, $secondHosts);
        $this->assertSame('completed', $run2->status);
        $this->assertSame('failed', $run2->sync_status);
        $this->assertStringContainsString('阿里云 OSS', (string) $run2->last_error);
        $this->assertStringContainsString('Cloudflare R2', (string) $run2->last_error);
        $this->assertStringContainsString('AWS S3', (string) $run2->last_error);
        $this->assertStringContainsString('腾讯云 COS', (string) $run2->last_error);
    }

    public function test_admin_settings_encrypts_cloud_secrets_validates_endpoints_and_tests_connections(): void
    {
        $this->authenticateOwner();

        // Reject SSRF / metadata endpoints and incomplete enabled providers.
        $this->postJson('/api/admin/settings', [
            'backup_r2_enabled' => true,
            'backup_r2_endpoint' => 'https://169.254.169.254/latest',
            'backup_r2_bucket' => 'shop-r2',
            'backup_r2_access_key' => 'ak',
            'backup_r2_secret_key' => 'sk',
        ])->assertUnprocessable()->assertJsonValidationErrors('backup_r2_endpoint');

        $this->postJson('/api/admin/settings', [
            'backup_oss_enabled' => true,
            'backup_oss_bucket' => 'shop-oss',
        ])->assertUnprocessable()->assertJsonValidationErrors('backup_oss_enabled');

        // Save multiple providers simultaneously.
        $this->postJson('/api/admin/settings', [
            'backup_r2_enabled' => true,
            'backup_r2_endpoint' => 'https://acct.r2.cloudflarestorage.com',
            'backup_r2_region' => 'auto',
            'backup_r2_bucket' => 'shop-r2',
            'backup_r2_prefix' => 'backups/r2',
            'backup_r2_access_key' => 'r2-access-key',
            'backup_r2_secret_key' => 'super-secret-r2-value',
            'backup_cos_enabled' => true,
            'backup_cos_region' => 'ap-shanghai',
            'backup_cos_bucket' => 'shop-1250000001',
            'backup_cos_access_key' => 'cos-secret-id',
            'backup_cos_secret_key' => 'super-secret-cos-value',
        ])->assertOk();

        // Secrets are AES-256-GCM encrypted in DB and masked in GET /api/admin/settings.
        $rawR2Secret = DB::table('settings')->where('key', 'backup_r2_secret_key')->value('value');
        $rawCosSecret = DB::table('settings')->where('key', 'backup_cos_secret_key')->value('value');
        $this->assertTrue(SecretCipher::isEncrypted((string) $rawR2Secret));
        $this->assertTrue(SecretCipher::isEncrypted((string) $rawCosSecret));
        $this->assertStringNotContainsString('super-secret-r2-value', (string) $rawR2Secret);

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('backup_r2_enabled', '1')
            ->assertJsonPath('backup_r2_secret_key', '********')
            ->assertJsonPath('backup_cos_enabled', '1')
            ->assertJsonPath('backup_cos_secret_key', '********')
            ->assertDontSee('super-secret-r2-value')
            ->assertDontSee('super-secret-cos-value');

        // Posting back ******** preserves stored secrets.
        $this->postJson('/api/admin/settings', [
            'backup_r2_secret_key' => '********',
            'backup_r2_prefix' => 'backups/r2-v2',
        ])->assertOk();
        $this->assertSame('super-secret-r2-value', setting('backup_r2_secret_key'));

        // Test connection endpoint uses stored secret when ******** is passed, and diagnoses 403 cleanly.
        Http::fake([
            'acct.r2.cloudflarestorage.com/*' => Http::response('', 200),
            'shop-1250000001.cos.ap-shanghai.myqcloud.com/*' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403),
        ]);

        $r2Test = $this->postJson('/api/admin/settings/test-backup-storage', [
            'provider' => 'r2',
            'endpoint' => 'https://acct.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'shop-r2',
            'access_key' => 'r2-access-key',
            'secret_key' => '********',
        ])->assertOk();
        $this->assertStringContainsString('Cloudflare R2 连接测试通过', (string) $r2Test->json('message'));

        $cosTest = $this->postJson('/api/admin/settings/test-backup-storage', [
            'provider' => 'cos',
            'region' => 'ap-shanghai',
            'bucket' => 'shop-1250000001',
            'access_key' => 'cos-secret-id',
            'secret_key' => '********',
        ])->assertStatus(422);
        $this->assertStringContainsString('AccessDenied', (string) $cosTest->json('message'));
    }

    public function test_shop_backup_validation_accepts_private_admin_path_in_archive(): void
    {
        $tempDir = storage_path('app/private/test-backup-admin-path-' . uniqid());
        mkdir($tempDir, 0700, true);
        $adminPathFile = Storage::disk('local')->path('admin-path');
        $hadAdminPath = is_file($adminPathFile);
        $previousAdminPath = $hadAdminPath ? file_get_contents($adminPathFile) : null;

        try {
            Storage::disk('local')->put('admin-path', 'a1b2c3d4e5f6g7h8i9j0k1l2');

            $service = new class($tempDir) extends ShopBackupService {
                public function __construct(private readonly string $dir) {}

                public function directory(): string
                {
                    return $this->dir;
                }

                protected function databaseProcess(array $command, ?string $database = null, ?callable $progress = null): void
                {
                    foreach ($command as $arg) {
                        if (str_starts_with($arg, '--file=')) {
                            file_put_contents(substr($arg, 7), 'PGDMP-TEST-DATA');
                        }
                    }
                }
            };

            $archive = $service->create();
            $manifest = $service->validate($archive);
            $this->assertArrayHasKey('private/admin-path', $manifest['files']);
            $this->assertArrayHasKey('database.dump', $manifest['files']);
        } finally {
            if ($hadAdminPath && $previousAdminPath !== null) {
                file_put_contents($adminPathFile, $previousAdminPath);
            } elseif (is_file($adminPathFile)) {
                @unlink($adminPathFile);
            }
            foreach (glob($tempDir . '/*') ?: [] as $item) {
                if (is_file($item)) {
                    @unlink($item);
                }
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    }
}
