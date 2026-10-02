<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admin extends Model
{
    protected $table = 'admins';

    protected $fillable = [
        'username',
        'password',
        'last_login_at',
        'last_login_ip',
        'role', 'permissions', 'is_active',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_revision', 'two_factor_last_counter',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array', 'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted', 'two_factor_recovery_codes' => 'array',
            'two_factor_last_counter' => 'integer', 'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function operationLogs(): HasMany
    {
        return $this->hasMany(OperationLog::class);
    }

    public function allows(string $area, string $action = 'read'): bool
    {
        if (! $this->is_active || ! array_key_exists($area, config('admin_permissions.areas', [])) || ! in_array($action, ['read', 'write'], true)) {
            return false;
        }
        return $this->role === 'owner' || in_array($area.':'.$action, $this->permissions ?? [], true) || ($action === 'read' && in_array($area.':write', $this->permissions ?? [], true));
    }
}
