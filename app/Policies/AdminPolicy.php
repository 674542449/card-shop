<?php

namespace App\Policies;

use App\Models\Admin;

/** Capability checks never inspect the request URI or HTTP method. */
final class AdminPolicy
{
    public static function permissions(): array
    {
        $permissions = [];
        foreach (array_keys(config('admin_permissions.areas', [])) as $area) {
            foreach (['read', 'write'] as $action) { $permissions[] = $area.':'.$action; }
        }
        return $permissions;
    }

    public static function allows(Admin $admin, string $capability): bool
    {
        if (! $admin->is_active) { return false; }
        $rule = config('admin_permissions.combinations.'.$capability);
        // Dot-separated capability names are literal keys in the definition.
        $rule ??= config('admin_permissions.combinations', [])[$capability] ?? null;
        if ($rule === null) {
            if (! in_array($capability, self::permissions(), true)) { return false; }
            [$area, $action] = explode(':', $capability, 2);
            return $admin->allows($area, $action);
        }
        if (($rule['owner'] ?? false) && $admin->role !== 'owner') { return false; }
        foreach ($rule['all'] ?? [] as $permission) {
            if (! self::allows($admin, $permission)) { return false; }
        }
        if (isset($rule['any'])) {
            foreach ($rule['any'] as $permission) {
                if (self::allows($admin, $permission)) { return true; }
            }
            return false;
        }
        return true;
    }

    public static function authorize(Admin $admin, string $capability): void
    {
        abort_unless(self::allows($admin, $capability), 403, '当前账户没有此操作权限。');
    }

    public static function definition(Admin $admin): array
    {
        $capabilities = array_merge(self::permissions(), array_keys(config('admin_permissions.combinations', [])));
        return ['areas' => config('admin_permissions.areas'), 'actions' => ['read' => '查看', 'write' => '修改'],
            'pages' => config('admin_permissions.pages'),
            'capabilities' => array_values(array_filter($capabilities, fn ($name) => self::allows($admin, $name)))];
    }
}
