<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Setting};
use Illuminate\Support\Facades\{Artisan, Hash, Http};
use Tests\TestCase;

class AdminCredentialContextSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function account(string $username = 'context-owner', array $attributes = []): Admin
    {
        return Admin::create(array_replace(['username' => $username, 'password' => Hash::make('dummy-admin-password'),
            'role' => 'owner', 'permissions' => [], 'is_active' => true], $attributes));
    }

    private function authenticate(Admin $admin): void
    {
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
    }

    public function test_nonempty_legitimate_permissions_can_be_saved_but_malformed_permissions_are_rejected(): void
    {
        $this->authenticate($this->account());
        $permissions = [];
        foreach (['overview', 'catalog', 'cards', 'payments', 'orders', 'refunds', 'content', 'coupons', 'blacklists', 'logs', 'settings', 'tokens', 'notifications', 'maintenance'] as $area) {
            foreach (['read', 'write'] as $action) { $permissions[] = $area.':'.$action; }
        }
        $payload = ['username' => 'permission-staff', 'password' => 'dummy-admin-password', 'role' => 'staff', 'permissions' => $permissions, 'is_active' => true];
        $created = $this->postJson('/api/admin/admins', $payload)->assertCreated()->assertJsonPath('permissions', $permissions);
        $id = $created->json('id');
        $this->putJson('/api/admin/admins/'.$id, $payload)->assertOk()->assertJsonPath('permissions', $permissions);
        $this->putJson('/api/admin/admins/'.$id, array_replace($payload, ['permissions' => ['owner:write', 'cards:read|owner']]))->assertUnprocessable();
        $this->assertSame($permissions, Admin::findOrFail($id)->permissions);
    }

    public function test_malformed_login_credentials_are_rejected_before_bcrypt_or_database_lookup(): void
    {
        $admin = $this->account();
        $this->postJson('/api/admin/login', ['username' => $admin->username, 'password' => "dummy-admin-password\0suffix"])->assertUnprocessable();
        $this->postJson('/api/admin/login', ['username' => $admin->username."\0suffix", 'password' => 'dummy-admin-password'])->assertUnprocessable();
        $this->post('/api/admin/login', ['username' => "bad\xFFusername", 'password' => 'dummy-admin-password'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_create_change_and_cli_reset_reject_nul_and_bcrypt_truncation(): void
    {
        $admin = $this->account();
        $this->authenticate($admin);
        $originalHash = $admin->password;
        $this->postJson('/api/admin/admins', ['username' => 'invalid-password-staff', 'password' => "dummy-admin-password\0suffix",
            'role' => 'staff', 'permissions' => [], 'is_active' => true])->assertUnprocessable();
        $this->postJson('/api/admin/password', ['current_password' => "dummy-admin-password\0suffix", 'new_password' => 'dummy-next-password',
            'new_password_confirmation' => 'dummy-next-password'])->assertUnprocessable();
        $this->postJson('/api/admin/password', ['current_password' => 'dummy-admin-password', 'new_password' => "dummy-next-password\0suffix",
            'new_password_confirmation' => "dummy-next-password\0suffix"])->assertUnprocessable();
        foreach (["dummy-next-password\0suffix", str_repeat('x', 73), "dummy-password\xFF"] as $invalid) {
            $this->assertSame(1, Artisan::call('admin:password', ['username' => $admin->username, '--password' => $invalid]));
        }
        $this->assertSame($originalHash, $admin->fresh()->password);
    }

    public function test_old_document_context_cannot_write_with_a_second_owner_or_staff_cookie(): void
    {
        $old = $this->account();
        $this->authenticate($old);
        $context = $this->getJson('/api/admin/me')->assertOk()->headers->get('X-Admin-Context');
        Setting::set('site_name', 'context-site', 'site');
        foreach ([['role' => 'owner', 'permissions' => []], ['role' => 'staff', 'permissions' => ['settings:write']]] as $i => $attributes) {
            $current = $this->account('current-account-'.$i, $attributes);
            $this->authenticate($current);
            $this->postJson('/api/admin/settings', ['site_name' => 'stale-owner-change'], ['X-Admin-Context' => $context])
                ->assertStatus(409)->assertJsonPath('code', 'admin_context_changed');
            $this->assertSame('context-site', Setting::where('key', 'site_name')->value('value'));
            $this->getJson('/api/admin/me')->assertOk()->assertHeader('X-Admin-Context', AdminAuth::contextFingerprint($current));
        }
    }
}
