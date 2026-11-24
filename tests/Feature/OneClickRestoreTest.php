<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\Admin;
use App\Security\SecretCipher;
use App\Services\CloudBackupStorageService;
use App\Services\RestoreQueue;
use App\Services\SettingService;
use App\Services\ShopBackupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OneClickRestoreTest extends TestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
        }
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function makeIsolatedBackupDir(): string
    {
        $dir = storage_path('app/private/test-oneclick-restore-' . Str::uuid());
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function makeIsolatedBackupService(string $dir, ?callable $restoreHook = null): ShopBackupService
    {
        return new class($dir, $restoreHook) extends ShopBackupService {
            private $restoreHook;

            public function __construct(private readonly string $dir, ?callable $restoreHook = null)
            {
                $this->restoreHook = $restoreHook;
            }

            public function directory(): string
            {
                return $this->dir;
            }

            protected function databaseProcess(array $command, ?string $database = null, ?callable $progress = null): void
            {
                foreach ($command as $arg) {
                    if (str_starts_with($arg, '--file=')) {
                        file_put_contents(substr($arg, 7), 'PGDMP-TEST-ONECLICK-RESTORE');
                    }
                }
            }

            public function restore(string $file, string $database, bool $includeConfig = false, bool $safetyBackup = false): void
            {
                if ($this->restoreHook !== null) {
                    ($this->restoreHook)($file, $database, $includeConfig, $safetyBackup);
                    return;
                }

                parent::restore($file, $database, $includeConfig, $safetyBackup);
            }
        };
    }

    private function authenticateOwner(string $password = 'restore-owner-password-123'): Admin
    {
        $admin = Admin::create([
            'username' => 'restore-owner-' . Str::lower(Str::random(6)),
            'password' => Hash::make($password),
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

    public function test_cloud_storage_list_and_download_sign_requests_filter_archives_and_enforce_prefix(): void
    {
        $service = app(CloudBackupStorageService::class);
        $captured = [];

        $xmlPayload = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
  <Name>shop-backups</Name>
  <Contents>
    <Key>prod/shop-20261124-030000-aaaa1111.tar.gz</Key>
    <LastModified>2026-11-24T03:00:05.000Z</LastModified>
    <Size>204800</Size>
  </Contents>
  <Contents>
    <Key>prod/shop-20261125-030000-bbbb2222.tar.gz</Key>
    <LastModified>2026-11-25T03:00:05.000Z</LastModified>
    <Size>307200</Size>
  </Contents>
  <Contents>
    <Key>prod/unrelated-notes.txt</Key>
    <LastModified>2026-11-25T04:00:00.000Z</LastModified>
    <Size>512</Size>
  </Contents>
  <Contents>
    <Key>prod/../shop-20261125-999999-evil.tar.gz</Key>
    <LastModified>2026-11-25T05:00:00.000Z</LastModified>
    <Size>128</Size>
  </Contents>
</ListBucketResult>
XML;

        Http::fake(function ($request) use (&$captured, $xmlPayload) {
            $captured[] = [
                'method' => $request->method(),
                'url' => $request->url(),
                'headers' => $request->headers(),
            ];
            if (str_contains($request->url(), 'list-type=2')) {
                return Http::response($xmlPayload, 200, ['Content-Type' => 'application/xml']);
            }

            return Http::response('DOWNLOADED-ARCHIVE-BYTES-OK', 200, ['Content-Type' => 'application/gzip']);
        });

        $r2Cfg = [
            'endpoint' => 'https://acct12345.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'shop-r2',
            'prefix' => 'prod',
            'access_key' => 'r2-ak',
            'secret_key' => 'r2-sk',
        ];
        $items = $service->listBackups('r2', $r2Cfg);
        $this->assertCount(2, $items);
        $this->assertSame('shop-20261125-030000-bbbb2222.tar.gz', $items[0]['name']);
        $this->assertSame('shop-20261124-030000-aaaa1111.tar.gz', $items[1]['name']);
        $this->assertSame('https://acct12345.r2.cloudflarestorage.com/shop-r2?list-type=2&max-keys=100&prefix=prod%2F', $captured[0]['url']);
        $this->assertStringContainsString('AWS4-HMAC-SHA256 Credential=r2-ak/', $captured[0]['headers']['Authorization'][0]);

        // Also verify Alibaba Cloud OSS native OSS4-HMAC-SHA256 list signing
        $ossCfg = [
            'endpoint' => '',
            'region' => 'oss-cn-hangzhou',
            'bucket' => 'shop-oss',
            'prefix' => 'prod',
            'access_key' => 'oss-ak',
            'secret_key' => 'oss-sk',
        ];
        $ossItems = $service->listBackups('oss', $ossCfg);
        $this->assertCount(2, $ossItems);
        $this->assertSame('https://shop-oss.oss-cn-hangzhou.aliyuncs.com/?list-type=2&max-keys=100&prefix=prod%2F', $captured[1]['url']);
        $this->assertStringContainsString('OSS4-HMAC-SHA256 Credential=oss-ak/', $captured[1]['headers']['Authorization'][0]);

        // Download verifies no double prefixing and writes exact bytes
        $dir = $this->makeIsolatedBackupDir();
        $dest = $dir . '/pulled.tar.gz';
        $service->downloadFile('r2', 'prod/shop-20261125-030000-bbbb2222.tar.gz', $dest, $r2Cfg);
        $this->assertSame('https://acct12345.r2.cloudflarestorage.com/shop-r2/prod/shop-20261125-030000-bbbb2222.tar.gz', $captured[2]['url']);
        $this->assertSame('DOWNLOADED-ARCHIVE-BYTES-OK', file_get_contents($dest));

        // Reject key outside configured prefix
        $this->expectException(\RuntimeException::class);
        $service->downloadFile('r2', 'other-prefix/shop-20261125-030000-bbbb2222.tar.gz', $dest, $r2Cfg);
    }

    public function test_restore_queue_prevalidates_keyring_and_executes_worker_cycle(): void
    {
        $dir = $this->makeIsolatedBackupDir();
        $restoredCalls = [];
        $backups = $this->makeIsolatedBackupService($dir, function (string $archive, string $db, bool $force, bool $allowActive) use (&$restoredCalls) {
            $this->assertTrue(app()->isDownForMaintenance(), 'Site must be in maintenance mode during restore.');
            $restoredCalls[] = compact('archive', 'db', 'force', 'allowActive');

            return ['files' => []];
        });

        $archive = $backups->create();
        $queue = new RestoreQueue($backups);

        // Enqueue succeeds for matching keyring and persists file-backed state
        $queued = $queue->enqueue($archive, 'local', 7);
        $this->assertSame('pending', $queued['status']);
        $this->assertSame(basename($archive), $queued['filename']);
        $this->assertArrayNotHasKey('archive', $queued);
        $this->assertTrue($queue->hasPending());

        // Duplicate enqueue while pending is rejected
        try {
            $queue->enqueue($archive, 'local', 7);
            $this->fail('Expected duplicate restore enqueue to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('已有恢复任务', $e->getMessage());
        }

        // Process runs artisan down -> restore -> artisan up and writes completed state
        $this->assertTrue($queue->process(2));
        $this->assertCount(1, $restoredCalls);
        $this->assertTrue($restoredCalls[0]['allowActive']);
        $this->assertFalse($restoredCalls[0]['force']);
        $this->assertFalse(app()->isDownForMaintenance(), 'Site must exit maintenance mode after successful restore.');

        $final = $queue->status();
        $this->assertSame('completed', $final['status']);
        $this->assertSame(100, $final['progress']);
        $this->assertFalse($final['maintenance_retained']);

        // When restore fails and leaves a restore-* staging dir, maintenance mode MUST be retained
        $failingBackups = $this->makeIsolatedBackupService($dir, function () use ($dir) {
            mkdir($dir . '/restore-leftover-stage', 0700, true);
            throw new \RuntimeException('模拟回滚中断');
        });
        $failingQueue = new RestoreQueue($failingBackups);
        $failingQueue->enqueue($archive, 'local', 7);
        $this->assertTrue($failingQueue->process(2));
        $failedState = $failingQueue->status();
        $this->assertSame('failed', $failedState['status']);
        $this->assertTrue($failedState['maintenance_retained']);
        $this->assertTrue(app()->isDownForMaintenance());
        Artisan::call('up');

        // Keyring mismatch must fail fast at enqueue() without entering maintenance mode
        $mismatchBackups = new class($dir) extends ShopBackupService {
            public function __construct(private readonly string $dir) {}

            public function directory(): string
            {
                return $this->dir;
            }

            public function validate(string $archive): array
            {
                return [
                    'version' => 1,
                    'created_at' => now()->toIso8601String(),
                    'secrets' => [
                        'keyring_id' => 'foreign-keyring-id-not-present',
                        'version_ids' => ['v99'],
                        'version_fingerprints' => ['v99' => str_repeat('a', 64)],
                    ],
                    'files' => ['database.dump' => ['size' => 10, 'sha256' => str_repeat('0', 64)]],
                ];
            }
        };
        $mismatchQueue = new RestoreQueue($mismatchBackups);
        try {
            $mismatchQueue->enqueue($archive, 'local', 7);
            $this->fail('Expected keyring mismatch to fail fast at enqueue.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('独立密钥', $e->getMessage());
        }
    }

    public function test_admin_endpoints_for_local_restore_upload_and_cloud_pull(): void
    {
        $dir = $this->makeIsolatedBackupDir();
        $backups = $this->makeIsolatedBackupService($dir);
        $archive = $backups->create();
        $archiveBytes = (string) file_get_contents($archive);
        $archiveName = basename($archive);

        $this->instance(ShopBackupService::class, $backups);
        $this->instance(RestoreQueue::class, new RestoreQueue($backups));

        // Configure enabled Cloudflare R2 target in settings
        $settings = app(SettingService::class);
        $settings->set('backup_r2_enabled', '1', 'backup');
        $settings->set('backup_r2_endpoint', 'https://acct999.r2.cloudflarestorage.com', 'backup');
        $settings->set('backup_r2_region', 'auto', 'backup');
        $settings->set('backup_r2_bucket', 'shop-r2-restore', 'backup');
        $settings->set('backup_r2_prefix', 'daily', 'backup');
        $settings->set('backup_r2_access_key', 'r2-test-ak', 'backup');
        $settings->set('backup_r2_secret_key', 'r2-test-sk', 'backup');

        $password = 'restore-owner-password-123';
        $this->authenticateOwner($password);

        // 1. GET /maintenance/backups includes restore state + enabled cloud providers
        $list = $this->getJson('/api/admin/maintenance/backups')->assertOk();
        $list->assertJsonPath('enabled_cloud_providers.0.key', 'r2');
        $list->assertJsonPath('restore', null);

        // 2. Local restore rejects wrong password & missing confirm, accepts valid password
        $this->postJson("/api/admin/maintenance/backups/{$archiveName}/restore", [
            'password' => 'wrong-password',
            'confirm' => 'RESTORE',
        ])->assertStatus(422);

        $this->postJson("/api/admin/maintenance/backups/{$archiveName}/restore", [
            'password' => $password,
            'confirm' => 'NOPE',
        ])->assertStatus(422);

        $this->postJson("/api/admin/maintenance/backups/{$archiveName}/restore", [
            'password' => $password,
            'confirm' => 'RESTORE',
        ])->assertAccepted()->assertJsonPath('restore.status', 'pending');

        $this->getJson('/api/admin/maintenance/restore-status')
            ->assertOk()
            ->assertJsonPath('restore.filename', $archiveName)
            ->assertJsonPath('restore.status', 'pending');

        // Clear pending state so subsequent upload/pull restore tests can enqueue cleanly
        @unlink($dir . '/.restore-state.json');
        Cache::flush();

        // 3. Upload rejects wrong extension and corrupted gzip; accepts valid .tar.gz
        $badExt = UploadedFile::fake()->createWithContent('not-archive.zip', $archiveBytes);
        $this->post('/api/admin/maintenance/backups/upload', ['file' => $badExt], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $corrupt = UploadedFile::fake()->createWithContent('shop-corrupt.tar.gz', 'not-a-valid-tar-gz');
        $this->post('/api/admin/maintenance/backups/upload', ['file' => $corrupt], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $validUpload = UploadedFile::fake()->createWithContent('migrated-backup.tar.gz', $archiveBytes);
        $uploaded = $this->post('/api/admin/maintenance/backups/upload', [
            'file' => $validUpload,
            'restore_now' => '0',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertStringStartsWith('shop-upload-', (string) $uploaded->json('file.name'));

        $validUploadAndRestore = UploadedFile::fake()->createWithContent('migrated-now.tar.gz', $archiveBytes);
        $this->post('/api/admin/maintenance/backups/upload', [
            'file' => $validUploadAndRestore,
            'restore_now' => '1',
            'password' => $password,
            'confirm' => 'RESTORE',
        ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('restore.source', 'upload')
            ->assertJsonPath('restore.status', 'pending');

        @unlink($dir . '/.restore-state.json');
        Cache::flush();

        // 4. Cloud list & cloud pull (download only + download & restore)
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult>
  <Contents>
    <Key>daily/{$archiveName}</Key>
    <LastModified>2026-11-25T04:00:00.000Z</LastModified>
    <Size>12345</Size>
  </Contents>
</ListBucketResult>
XML;
        Http::fake(function ($request) use ($xml, $archiveBytes) {
            if (str_contains($request->url(), 'list-type=2')) {
                return Http::response($xml, 200, ['Content-Type' => 'application/xml']);
            }

            return Http::response($archiveBytes, 200, ['Content-Type' => 'application/gzip']);
        });

        $this->getJson('/api/admin/maintenance/cloud-backups?provider=r2')
            ->assertOk()
            ->assertJsonPath('provider', 'r2')
            ->assertJsonPath('data.0.name', $archiveName);

        $pulledOnly = $this->postJson('/api/admin/maintenance/cloud-backups/pull', [
            'provider' => 'r2',
            'key' => "daily/{$archiveName}",
            'restore_now' => false,
        ])->assertCreated();
        $this->assertStringStartsWith('shop-r2-', (string) $pulledOnly->json('file.name'));

        $this->postJson('/api/admin/maintenance/cloud-backups/pull', [
            'provider' => 'r2',
            'key' => "daily/{$archiveName}",
            'restore_now' => true,
            'password' => $password,
            'confirm' => 'RESTORE',
        ])->assertAccepted()
            ->assertJsonPath('restore.source', 'cloud:r2')
            ->assertJsonPath('restore.status', 'pending');

        // 5. Inactive staff account cannot call restore or cloud-pull endpoints (401)
        $staff = Admin::create([
            'username' => 'restore-staff-' . Str::lower(Str::random(6)),
            'password' => Hash::make('staff-password-123'),
            'role' => 'staff',
            'permissions' => ['maintenance:read', 'maintenance:write'],
            'is_active' => false,
        ]);
        Cache::flush();
        $this->withSession([
            'admin_id' => $staff->id,
            'admin_pw' => AdminAuth::passwordFingerprint($staff->password),
        ]);
        $this->postJson("/api/admin/maintenance/backups/{$archiveName}/restore", [
            'password' => 'staff-password-123',
            'confirm' => 'RESTORE',
        ])->assertUnauthorized();
        $this->postJson('/api/admin/maintenance/cloud-backups/pull', [
            'provider' => 'r2',
            'key' => "daily/{$archiveName}",
        ])->assertUnauthorized();
    }

    public function test_backup_worker_executes_pending_restore_via_artisan_once_outside_shared_lock(): void
    {
        $dir = $this->makeIsolatedBackupDir();
        $acquiredExclusive = false;
        $backups = $this->makeIsolatedBackupService($dir, function () use (&$acquiredExclusive) {
            app(\App\Services\MaintenanceWriteBarrier::class)->restore(function () use (&$acquiredExclusive) {
                $acquiredExclusive = true;
            });
        });

        $archive = $backups->create();
        $queue = new RestoreQueue($backups);
        $queue->enqueue($archive, 'local', 1);

        $this->instance(ShopBackupService::class, $backups);
        $this->instance(RestoreQueue::class, $queue);

        $this->assertSame(0, $this->artisan('shop:backup-work', ['--once' => true]));
        $this->assertTrue($acquiredExclusive, 'Worker must execute restore outside LOCK_SH so LOCK_EX succeeds.');
        $this->assertSame('completed', $queue->status()['status']);
        $this->assertFalse(app()->isDownForMaintenance());
    }
}
