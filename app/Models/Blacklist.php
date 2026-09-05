<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Blacklist extends Model
{
    public $timestamps = false;

    protected $table = 'blacklists';

    protected $fillable = [
        'type',
        'value',
        'reason',
        'expires_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Blacklist $model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    /**
     * Limit a query to bans that are still in force — permanent (expires_at null)
     * or not yet past their expiry. A honeypot ban that has lapsed must stop
     * blocking on its own; nothing sweeps the table, so the filter is what expires it.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Check if the given IP or email is blocked (by an unexpired ban).
     */
    public static function isBlocked(string $ip, ?string $email = null): bool
    {
        $query = static::active()->where(function ($outer) use ($ip, $email) {
            $outer->where(function ($q) use ($ip) {
                $q->where('type', 'ip')->where('value', $ip);
            });

            if ($email !== null) {
                // lower() on both sides. The CheckBlacklist middleware was made
                // case-insensitive earlier; this method was not, and it is the one
                // OrderService::createOrder uses — so a blocked address could order
                // again through /api/v1/orders by capitalising a single letter.
                $lower = mb_strtolower($email);

                $outer->orWhere(function ($q) use ($lower) {
                    $q->where('type', 'email')->whereRaw('lower(value) = ?', [$lower]);
                });
            }
        });

        return $query->exists();
    }

    /**
     * Ban an IP — used by the scanner honeypot.
     *
     * $minutes null/<=0 means permanent; the honeypot passes a TTL so a false
     * positive self-heals. Never DOWNGRADES an existing manual (permanent) ban to
     * a temporary one: if a row already exists and it was not put there by the
     * honeypot, it is left exactly as the operator set it. Repeated probes from the
     * same scanner just refresh the honeypot ban's expiry.
     *
     * The CheckBlacklist cache is dropped for this IP so the ban bites on the very
     * next request rather than up to 5 minutes later when its cached "not blocked"
     * entry would otherwise expire — "直接 ban" has to mean immediately.
     */
    public static function banIp(string $ip, string $reason, ?int $minutes = null, string $source = 'honeypot'): void
    {
        $existing = static::where('type', 'ip')->where('value', $ip)->first();

        // A manual/permanent ban outranks the honeypot; do not touch it.
        if ($existing && $existing->source !== 'honeypot') {
            return;
        }

        $expiresAt = ($minutes !== null && $minutes > 0) ? now()->addMinutes($minutes) : null;

        static::updateOrCreate(
            ['type' => 'ip', 'value' => $ip],
            [
                'reason' => mb_substr($reason, 0, 500),
                'expires_at' => $expiresAt,
                'source' => $source,
            ],
        );

        // Same key format CheckBlacklist::isBlocked() uses, so the stale "not
        // blocked" verdict is gone before the scanner's next request.
        try {
            Cache::store('redis')->forget('blacklist:ip:' . md5($ip));
        } catch (\Throwable $e) {
            // No cache to clear is not a failure — the DB row is the source of truth.
        }
    }
}
