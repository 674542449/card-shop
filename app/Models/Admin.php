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
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array', 'is_active' => 'boolean',
        ];
    }

    public function operationLogs(): HasMany
    {
        return $this->hasMany(OperationLog::class);
    }

    public function allows(string $area, string $action = 'read'): bool
    {
        return $this->role === 'owner' || in_array($area.':'.$action, $this->permissions ?? [], true) || ($action === 'read' && in_array($area.':write', $this->permissions ?? [], true));
    }
}
