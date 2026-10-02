<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Setting};
use App\Services\AdminTwoFactorService;
use Illuminate\Support\Facades\{Artisan, DB, Hash};
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    private function admin(): Admin
    {
        return Admin::create(['username' => 'factor-owner', 'password' => Hash::make('dummy-factor-password'), 'role' => 'owner', 'is_active' => true]);
    }
    private function login(Admin $admin): void
    {
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password), 'admin_factor' => $admin->two_factor_revision]);
    }
    private function enable(Admin $admin): array
    {
        $this->login($admin);
        $secret = $this->postJson('/api/admin/two-factor/setup', ['current_password' => 'dummy-factor-password'])->assertOk()->json('secret');
        $code = app(AdminTwoFactorService::class)->code($secret, now()->timestamp);
        return $this->postJson('/api/admin/two-factor/confirm', ['code' => $code])->assertOk()->json('recovery_codes');
    }

    public function test_rfc6238_sha1_vectors_including_post_2038_time(): void
    {
        $service = app(AdminTwoFactorService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $expected) {
            $this->assertSame($expected, $service->code($secret, $time, 8));
        }
    }

    public function test_enrollment_requires_password_and_confirmation_and_never_serializes_secrets(): void
    {
        $admin = $this->admin(); $this->login($admin);
        $this->postJson('/api/admin/two-factor/setup', ['current_password' => 'wrong-password'])->assertUnprocessable();
        $this->assertNull($admin->fresh()->two_factor_confirmed_at);
        $codes = $this->enable($admin); $admin->refresh();
        $this->assertCount(8, $codes);
        $this->assertNotSame($admin->two_factor_secret, DB::table('admins')->where('id', $admin->id)->value('two_factor_secret'));
        $this->assertStringNotContainsString($codes[0], DB::table('admins')->where('id', $admin->id)->value('two_factor_recovery_codes'));
        $this->assertArrayNotHasKey('two_factor_secret', $admin->toArray());
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('two_factor_enabled', true)->assertJsonPath('recovery_codes_remaining', 8);
    }

    public function test_password_only_never_grants_access_and_recovery_is_single_use(): void
    {
        $admin = $this->admin(); $codes = $this->enable($admin);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->postJson('/api/admin/login', ['username' => $admin->username, 'password' => 'dummy-factor-password'])->assertOk()->assertJsonPath('two_factor_required', true);
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->postJson('/api/admin/login/challenge', ['code' => 'wrong-code'])->assertUnprocessable();
        $this->postJson('/api/admin/login/challenge', ['code' => $codes[0]])->assertOk();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('recovery_codes_remaining', 7);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->postJson('/api/admin/login', ['username' => $admin->username, 'password' => 'dummy-factor-password'])->assertOk();
        $this->postJson('/api/admin/login/challenge', ['code' => $codes[0]])->assertUnprocessable();
    }

    public function test_counters_cannot_replay_and_expired_challenge_requires_password_again(): void
    {
        $admin = $this->admin(); $this->enable($admin); $admin->refresh();
        $service = app(AdminTwoFactorService::class);
        $this->assertFalse($service->consume($admin, $service->code($admin->two_factor_secret, now()->timestamp)));
        $this->travel(31)->seconds();
        $code = $service->code($admin->two_factor_secret, now()->timestamp);
        $this->assertTrue($service->consume($admin, $code));
        $this->assertFalse($service->consume($admin->fresh(), $code));
        $this->postJson('/api/admin/login', ['username' => $admin->username, 'password' => 'dummy-factor-password'])->assertOk();
        $this->travel(301)->seconds();
        $this->postJson('/api/admin/login/challenge', ['code' => $service->code($admin->two_factor_secret, now()->timestamp)])->assertUnauthorized();
    }

    public function test_toggle_invalidates_old_sessions_and_requires_both_factors_to_disable(): void
    {
        $admin = $this->admin(); $oldPassword = AdminAuth::passwordFingerprint($admin->password); $codes = $this->enable($admin); $admin->refresh();
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => $oldPassword, 'admin_factor' => null]);
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->login($admin);
        $this->postJson('/api/admin/two-factor/disable', ['current_password' => 'wrong-password', 'code' => $codes[0]])->assertUnprocessable();
        $this->postJson('/api/admin/two-factor/disable', ['current_password' => 'dummy-factor-password', 'code' => 'wrong-code'])->assertUnprocessable();
        $oldRevision = $admin->two_factor_revision;
        $this->postJson('/api/admin/two-factor/disable', ['current_password' => 'dummy-factor-password', 'code' => $codes[0]])->assertOk();
        $this->assertNull($admin->fresh()->two_factor_confirmed_at);
        $this->getJson('/api/admin/me')->assertOk();
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => $oldPassword, 'admin_factor' => $oldRevision]);
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_setup_expires_and_brute_force_budget_is_account_wide(): void
    {
        $admin = $this->admin(); $this->login($admin);
        $secret = $this->postJson('/api/admin/two-factor/setup', ['current_password' => 'dummy-factor-password'])->assertOk()->json('secret');
        $this->travel(301)->seconds();
        $this->postJson('/api/admin/two-factor/confirm', ['code' => app(AdminTwoFactorService::class)->code($secret, now()->timestamp)])->assertUnprocessable();
        $this->travelBack();
        $this->enable($admin);
        $this->postJson('/api/admin/login', ['username' => $admin->username, 'password' => 'dummy-factor-password'])->assertOk();
        for ($i = 0; $i < 10; $i++) { $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])->postJson('/api/admin/login/challenge', ['code' => 'invalid'])->assertUnprocessable(); }
        $this->postJson('/api/admin/login/challenge', ['code' => 'invalid'])->assertStatus(429);
    }

    public function test_console_recovery_invalidates_sessions_without_exposing_secret_or_changing_password(): void
    {
        $admin = $this->admin(); $this->enable($admin); $password = $admin->fresh()->password;
        $this->assertSame(0, Artisan::call('admin:2fa-reset', ['username' => $admin->username, '--force' => true]));
        $this->assertSame($password, $admin->fresh()->password);
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->postJson('/api/admin/login', ['username' => $admin->username, 'password' => 'dummy-factor-password'])->assertOk()->assertJsonMissing(['two_factor_required' => true]);
    }

    public function test_refund_and_backup_switches_are_disabled_by_default_and_owner_only(): void
    {
        $owner = $this->admin(); $this->login($owner);
        $this->postJson('/api/admin/settings', ['refund_enabled' => true, 'backup_auto_enabled' => true])->assertOk();
        $staff = Admin::create(['username' => 'factor-staff', 'password' => 'dummy-factor-password', 'role' => 'staff', 'permissions' => ['settings:write'], 'is_active' => true]);
        $this->login($staff);
        $this->postJson('/api/admin/settings', ['refund_enabled' => false, 'site_name' => 'unauthorized-change'])->assertForbidden();
        $this->postJson('/api/admin/settings', ['backup_auto_enabled' => false])->assertForbidden();
        $this->assertSame('1', Setting::where('key', 'refund_enabled')->value('value'));
    }

    public function test_html_validation_never_flashes_factor_codes_or_new_passwords(): void
    {
        $admin = $this->admin(); $this->login($admin);
        $this->post('/api/admin/two-factor/disable', ['code' => 'AAAAA-BBBBB-CCCCC-DDDDD'], ['Accept' => 'text/html'])
            ->assertRedirect()->assertSessionMissing('_old_input.code');
        $this->post('/api/admin/password', ['new_password' => 'dummy-new-password', 'new_password_confirmation' => 'mismatch'], ['Accept' => 'text/html'])
            ->assertRedirect()->assertSessionMissing('_old_input.new_password')->assertSessionMissing('_old_input.new_password_confirmation');
    }

    public function test_old_setup_cannot_be_reused_after_console_factor_reset(): void
    {
        $admin = $this->admin(); $this->login($admin);
        $secret = $this->postJson('/api/admin/two-factor/setup', ['current_password' => 'dummy-factor-password'])->assertOk()->json('secret');
        Artisan::call('admin:2fa-reset', ['username' => $admin->username, '--force' => true]);
        $this->login($admin->fresh());
        $this->postJson('/api/admin/two-factor/confirm', ['code' => app(AdminTwoFactorService::class)->code($secret, now()->timestamp)])->assertUnprocessable();
        $this->assertNull($admin->fresh()->two_factor_confirmed_at);
    }

    public function test_factor_confirmation_does_not_adopt_a_reset_after_its_write(): void
    {
        $admin = $this->admin(); $this->login($admin);
        $secret = $this->postJson('/api/admin/two-factor/setup', ['current_password' => 'dummy-factor-password'])->assertOk()->json('secret');
        Admin::saved(function (Admin $saved) {
            if ($saved->wasChanged('two_factor_confirmed_at') && $saved->two_factor_confirmed_at) {
                DB::table('admins')->where('id', $saved->id)->update(['two_factor_revision' => 'later-server-reset', 'two_factor_confirmed_at' => null]);
            }
        });
        try {
            $this->postJson('/api/admin/two-factor/confirm', ['code' => app(AdminTwoFactorService::class)->code($secret, now()->timestamp)])->assertOk();
            $this->getJson('/api/admin/me')->assertUnauthorized();
        } finally { Admin::flushEventListeners(); }
    }

    public function test_password_change_does_not_adopt_a_later_password_reset(): void
    {
        $admin = $this->admin(); $this->login($admin);
        Admin::saved(function (Admin $saved) {
            if ($saved->wasChanged('password')) {
                DB::table('admins')->where('id', $saved->id)->update(['password' => Hash::make('later-server-password')]);
            }
        });
        try {
            $this->postJson('/api/admin/password', ['current_password' => 'dummy-factor-password', 'new_password' => 'dummy-next-password', 'new_password_confirmation' => 'dummy-next-password'])->assertOk();
            $this->getJson('/api/admin/me')->assertUnauthorized();
        } finally { Admin::flushEventListeners(); }
    }
}
