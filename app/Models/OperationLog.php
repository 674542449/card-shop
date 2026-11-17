<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationLog extends Model
{
    public $timestamps = false;

    protected $table = 'operation_logs';

    protected $fillable = [
        'admin_id',
        'action',
        'target_type',
        'target_id',
        'detail',
        'ip',
    ];

    protected static function booted(): void
    {
        static::creating(function (OperationLog $model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'target_id' => 'integer',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Create a log entry using the current admin session and request IP.
     */
    public static function log(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $detail = null,
    ): static {
        return static::create([
            'admin_id' => session('admin_id'),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'detail' => $detail,
            'ip' => request()->ip(),
        ]);
    }
}
