<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Blacklist extends Model
{
    public $timestamps = false;

    protected $table = 'blacklists';

    protected $fillable = [
        'type',
        'value',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Blacklist $model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    /**
     * Check if the given IP or email is blocked.
     */
    public static function isBlocked(string $ip, ?string $email = null): bool
    {
        $query = static::where(function ($q) use ($ip) {
            $q->where('type', 'ip')->where('value', $ip);
        });

        if ($email !== null) {
            // lower() on both sides. The CheckBlacklist middleware was made
            // case-insensitive earlier; this method was not, and it is the one
            // OrderService::createOrder uses — so a blocked address could order
            // again through /api/v1/orders by capitalising a single letter.
            $lower = mb_strtolower($email);

            $query->orWhere(function ($q) use ($lower) {
                $q->where('type', 'email')->whereRaw('lower(value) = ?', [$lower]);
            });
        }

        return $query->exists();
    }
}
