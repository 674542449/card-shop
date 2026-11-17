<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Setting};
use App\Policies\AdminPolicy;
use Illuminate\Support\Facades\{Artisan, DB, Hash, Schema};
use Tests\TestCase;

class SingleAdministratorTest extends TestCase
{
    private function signIn(): Admin
    {
        $admin = Admin::create(['username' => 'single-admin', 'password' => 'dummy-single-password', 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        return $admin;
    }

    public function test_database_enforces_one_active_administrator(): void
    {
        $first = $this->signIn();
        try {
            DB::transaction(fn () => Admin::create(['username' => 'second', 'password' => 'dummy-second-password', 'is_active' => true]));
            $this->fail('A second active administrator was accepted.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame('23505', $error->getCode());
        }
        $this->assertSame(1, Admin::where('is_active', true)->count());
        $this->assertSame($first->id, Admin::where('is_active', true)->value('id'));
        $this->assertFalse(Schema::hasColumn('admins', 'role'));
        $this->assertFalse(Schema::hasColumn('admins', 'permissions'));
    }

    public function test_no_account_creation_role_assignment_or_account_management_endpoint_remains(): void
    {
        $this->signIn();
        foreach (['/api/admin/admins', '/api/admin/%61dmins'] as $path) {
            $this->getJson($path)->assertNotFound();
            $this->postJson($path, ['username' => 'forged', 'password' => 'dummy-forged-password', 'role' => 'owner', 'permissions' => ['cards:write']])->assertNotFound();
            $this->putJson($path.'/1', ['is_active' => false])->assertNotFound();
        }
        $this->assertSame(1, Admin::count());
        $me = $this->getJson('/api/admin/me')->assertOk()->assertJsonMissingPath('role')->assertJsonMissingPath('permissions');
        $this->assertNotContains('/admins', array_column($me->json('permission_definition.pages'), 'path'));
        $this->assertNotContains('accounts', $me->json('permission_definition.capabilities'));
    }

    public function test_administrator_can_use_all_known_business_capabilities_but_unknown_ones_fail_closed(): void
    {
        $admin = $this->signIn();
        foreach (AdminPolicy::definition($admin)['capabilities'] as $capability) {
            $this->assertTrue(AdminPolicy::allows($admin, $capability));
        }
        $this->assertTrue(AdminPolicy::allows($admin, 'backups.write'));
        $this->assertTrue(AdminPolicy::allows($admin, 'orders.mark_paid'));
        $this->assertFalse(AdminPolicy::allows($admin, 'unknown:write'));
        $this->assertFalse($admin->allows('catalog', 'delete'));
        $admin->is_active = false;
        foreach (['self', 'settings:write', 'cards:read', 'backups.write'] as $capability) {
            $this->assertFalse(AdminPolicy::allows($admin, $capability));
        }
    }

    public function test_disabled_historical_account_cannot_login_or_use_a_forged_session(): void
    {
        $admin = Admin::create(['username' => 'retired-account', 'password' => 'dummy-retired-password', 'is_active' => false]);
        $this->postJson('/api/admin/login', ['username' => $admin->username, 'password' => 'dummy-retired-password', 'role' => 'owner'])->assertUnprocessable();
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        foreach (['settings', 'products', 'orders', 'maintenance/backups', 'api-tokens'] as $path) {
            $this->getJson('/api/admin/'.$path)->assertUnauthorized();
            if ($path !== 'orders') {
                $this->postJson('/api/admin/'.$path, ['role' => 'owner', 'permissions' => ['cards:write']])->assertUnauthorized();
            }
        }
    }

    public function test_seeding_again_with_a_different_username_does_not_create_or_reset_an_account(): void
    {
        $admin = $this->signIn(); $hash = $admin->password;
        $previous = getenv('ADMIN_USERNAME');
        putenv('ADMIN_USERNAME=changed-environment-name');
        try { Artisan::call('db:seed', ['--force' => true]); }
        finally { $previous === false ? putenv('ADMIN_USERNAME') : putenv('ADMIN_USERNAME='.$previous); }
        $this->assertSame(1, Admin::count());
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertSame('single-admin', $admin->fresh()->username);
    }

    public function test_setting_switches_and_secret_masks_work_without_roles(): void
    {
        $this->signIn();
        Setting::set('epay_merchant_key', 'dummy-original-key');
        $this->postJson('/api/admin/settings', ['refund_enabled' => true, 'backup_auto_enabled' => false, 'epay_merchant_key' => '********'])->assertOk();
        $this->assertSame('dummy-original-key', Setting::get('epay_merchant_key'));
        $this->assertSame('1', Setting::get('refund_enabled'));
        $this->getJson('/api/admin/settings')->assertOk()->assertJsonPath('epay_merchant_key', '********');
        $this->postJson('/api/admin/settings', ['refund_enabled' => 'not-a-switch'])->assertUnprocessable();
        $this->assertSame('1', Setting::get('refund_enabled'));
    }

    public function test_legacy_conversion_keeps_primary_credentials_and_disables_every_other_account(): void
    {
        $migration = require database_path('migrations/2026_10_07_000001_use_single_administrator.php');
        $migration->down();
        $hash = Hash::make('dummy-retained-password');
        $staff = DB::table('admins')->insertGetId(['username' => 'former-staff', 'password' => $hash, 'role' => 'staff', 'is_active' => true]);
        $primary = DB::table('admins')->insertGetId(['username' => 'primary-admin', 'password' => $hash, 'role' => 'owner', 'is_active' => true, 'two_factor_revision' => 'retained-factor-revision']);
        $other = DB::table('admins')->insertGetId(['username' => 'other-owner', 'password' => $hash, 'role' => 'owner', 'is_active' => true]);
        $migration->up();
        $this->assertSame($primary, Admin::where('is_active', true)->value('id'));
        $this->assertFalse(Admin::findOrFail($staff)->is_active);
        $this->assertFalse(Admin::findOrFail($other)->is_active);
        $this->assertSame($hash, Admin::findOrFail($primary)->password);
        $this->assertSame('retained-factor-revision', Admin::findOrFail($primary)->two_factor_revision);
        $this->assertSame(3, Admin::count());
    }
}
