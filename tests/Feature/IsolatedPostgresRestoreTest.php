<?php

namespace Tests\Feature;

use App\Services\ShopBackupService;
use PDO;
use Tests\TestCase;

/** Optional locally, mandatory in CI. Both databases contain only this test's virtual rows. */
class IsolatedPostgresRestoreTest extends TestCase
{
    public function test_real_postgres_restore_commits_verified_snapshot_and_rolls_back_on_error(): void
    {
        if (getenv('SHOP_RUN_RESTORE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Enable SHOP_RUN_RESTORE_INTEGRATION=1 with PostgreSQL 17 clients and a test-only CREATEDB account.');
        }
        $config = config('database.connections.pgsql');
        $this->assertSame('cardshop_testing', $config['database']);
        $suffix = bin2hex(random_bytes(6));
        $source = 'cardshop_restore_test_src_'.$suffix;
        $target = 'cardshop_restore_test_dst_'.$suffix;
        $directory = base_path('.local/pg-restore-fixture-'.$suffix);
        mkdir($directory, 0700, true);
        $connect = fn ($database) => new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$database,
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin = $connect('cardshop_testing');
        $sourceCreated = false;
        $targetCreated = false;
        $sourceConnection = $targetConnection = null;
        try {
            // Generated identifiers are bounded; never reuse, clean or restore the app/test database.
            $admin->exec('CREATE DATABASE "'.$source.'"');
            $sourceCreated = true;
            $admin->exec('CREATE DATABASE "'.$target.'"');
            $targetCreated = true;
            $sourceConnection = $connect($source);
            $targetConnection = $connect($target);
            $sourceConnection->exec("CREATE TABLE runtime_restore_a (id integer PRIMARY KEY, value text NOT NULL);
                CREATE TABLE runtime_restore_z (id integer PRIMARY KEY, value text NOT NULL);
                INSERT INTO runtime_restore_a VALUES (1, 'source-a');
                INSERT INTO runtime_restore_z VALUES (1, 'source-z')");
            $targetConnection->exec("CREATE TABLE runtime_restore_a (id integer PRIMARY KEY, value text NOT NULL);
                CREATE TABLE runtime_restore_z (id integer PRIMARY KEY, value text NOT NULL);
                INSERT INTO runtime_restore_a VALUES (9, 'target-a');
                INSERT INTO runtime_restore_z VALUES (9, 'target-z');
                CREATE TABLE runtime_restore_fk_block (id integer REFERENCES runtime_restore_a(id))");
            $service = new class($directory, $source) extends ShopBackupService {
                public function __construct(private readonly string $fixtureDirectory, private readonly string $sourceDatabase) {}
                public function directory(): string { return $this->fixtureDirectory; }
                protected function backupInputs(string $stage): array {
                    // No environment file, real card data, uploads or application archives are read.
                    return ['database.dump' => $stage.'/database.dump'];
                }
                protected function databaseProcess(array $command, ?string $database = null, ?callable $progress = null): void {
                    if (in_array('--format=custom', $command, true)) {
                        $command[] = '--dbname='.$this->sourceDatabase;
                        $database = $this->sourceDatabase;
                    }
                    parent::databaseProcess($command, $database, $progress);
                }
            };
            $archive = $service->create();
            $this->assertSame(['database.dump'], array_keys($service->validate($archive)['files']));
            try {
                $service->restore($archive, $target);
                $this->fail('Expected the unrelated target FK to reject destructive restore.');
            } catch (\RuntimeException $failure) {
                $this->assertStringContainsString('数据库备份/恢复命令失败', $failure->getMessage());
            }
            $this->assertSame('target-a', $targetConnection->query('SELECT value FROM runtime_restore_a')->fetchColumn());
            $this->assertSame('target-z', $targetConnection->query('SELECT value FROM runtime_restore_z')->fetchColumn());
            $this->assertSame(2, (int) $targetConnection->query("SELECT count(*) FROM pg_constraint WHERE contype='p' AND conrelid IN ('runtime_restore_a'::regclass, 'runtime_restore_z'::regclass)")->fetchColumn());
            $targetConnection->exec('DROP TABLE runtime_restore_fk_block');
            $service->restore($archive, $target);
            $this->assertSame('source-a', $targetConnection->query('SELECT value FROM runtime_restore_a')->fetchColumn());
            $this->assertSame('source-z', $targetConnection->query('SELECT value FROM runtime_restore_z')->fetchColumn());
            $this->assertSame([], glob($directory.'/restore-*') ?: []);
        } finally {
            $sourceConnection = $targetConnection = null;
            if ($sourceCreated) { $admin->exec('DROP DATABASE "'.$source.'"'); }
            if ($targetCreated) { $admin->exec('DROP DATABASE "'.$target.'"'); }
            foreach (glob($directory.'/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
            rmdir($directory);
        }
    }
}
