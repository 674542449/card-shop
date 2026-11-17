<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['lease_token', 'schedule_key'];
    protected $attributes = [
        'source' => 'manual', 'status' => 'pending', 'progress' => 0,
        'phase' => '等待备份进程', 'attempts' => 0, 'sync_status' => 'disabled',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer', 'attempts' => 'integer', 'size' => 'integer',
            'lease_expires_at' => 'datetime', 'started_at' => 'datetime',
            'finished_at' => 'datetime', 'synced_at' => 'datetime', 'health_acknowledged_at' => 'datetime',
        ];
    }
}
