<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminPath;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class RandomAdminPathInstallTest extends TestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        AdminPath::resetCache();
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function makeTempStorageFile(): string
    {
        $dir = base_path('.local/admin-path-test-' . Str::uuid());
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir . '/admin-path';
    }

    public function test_generate_produces_unique_24_char_high_strength_segments(): void
    {
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $segment = AdminPath::generate();
            $this->assertSame(24, strlen($segment));
            $this->assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9]{23}$/D', $segment);
            $this->assertNotSame('admin', strtolower($segment));
            $this->assertNotContains(strtolower($segment), AdminPath::RESERVED);
            $seen[$segment] = true;
        }
        $this->assertCount(100, $seen);
    }

    public function test_empty_or_invalid_admin_path_persists_and_reuses_high_strength_random_segment(): void
    {
        $file = $this->makeTempStorageFile();

        $first = AdminPath::resolve('', $file);
        $this->assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9]{23}$/D', $first);
        $this->assertNotSame('admin', strtolower($first));
        $this->assertFileExists($file);
        $this->assertSame($first, trim((string) file_get_contents($file)));

        // Subsequent boots reuse the exact same persisted path.
        $this->assertSame($first, AdminPath::resolve('', $file));

        // Reserved or malformed ADMIN_PATH values never expose "/admin"; they fall back to persisted random path.
        foreach (['api', 'order', 'health', 'admin-assets', 'bad/slash', str_repeat('a', 40)] as $invalid) {
            $this->assertSame($first, AdminPath::resolve($invalid, $file), "Failed for invalid ADMIN_PATH={$invalid}");
        }

        // Explicit valid custom path takes precedence without mutating the persisted file.
        $this->assertSame('CustomConsole_99', AdminPath::resolve('/CustomConsole_99/', $file));
        $this->assertSame($first, trim((string) file_get_contents($file)));

        // Regenerate rotates the persisted path cleanly.
        $rotated = AdminPath::regenerate($file);
        $this->assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9]{23}$/D', $rotated);
        $this->assertNotSame($first, $rotated);
        $this->assertSame($rotated, AdminPath::resolve('', $file));
    }

    public function test_artisan_admin_path_command_outputs_active_segment_and_warns_when_env_locks_regenerate(): void
    {
        $this->assertSame(0, Artisan::call('admin:path', ['--raw' => true]));
        $this->assertSame(admin_path(), trim(Artisan::output()));

        // Under phpunit.xml ADMIN_PATH=admin is explicitly set in env, so --regenerate refuses silent mismatch.
        $this->assertSame(1, Artisan::call('admin:path', ['--regenerate' => true]));
        $this->assertStringContainsString('ADMIN_PATH=', Artisan::output());
    }

    public function test_first_install_seeder_records_admin_path_in_initial_credentials_file(): void
    {
        Admin::query()->delete();
        $credFile = storage_path('app/initial-admin-password.txt');
        $backup = is_file($credFile) ? file_get_contents($credFile) : null;
        @unlink($credFile);

        try {
            app(DatabaseSeeder::class)->run();
            $this->assertFileExists($credFile);
            $contents = (string) file_get_contents($credFile);
            $this->assertStringContainsString('admin_path: /' . admin_path(), $contents);
        } finally {
            if ($backup !== null) {
                file_put_contents($credFile, $backup);
            } else {
                @unlink($credFile);
            }
        }
    }
}
