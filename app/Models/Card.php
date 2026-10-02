<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Builders\CardQueryBuilder;
use App\Security\SecretCipher;

class Card extends Model
{
    protected $table = 'cards';

    protected $fillable = [
        'product_id',
        'order_id',
        'content',
        'status',
        'locked_at',
        'sold_at',
    ];

    // A card is the sold secret, not ordinary catalog metadata. Serialization
    // must opt in at an explicitly authorized delivery/admin boundary; merely
    // eager-loading a relation must never disclose stock to a lower-trust caller.
    protected $hidden = ['content', 'content_fingerprint'];

    public function newEloquentBuilder($query): CardQueryBuilder
    {
        return new CardQueryBuilder($query);
    }

    public function getContentAttribute(mixed $value): string
    {
        return app(SecretCipher::class)->decrypt((string) $value, 'card-content');
    }

    public function setContentAttribute(string $value): void
    {
        $cipher = app(SecretCipher::class);
        $this->attributes['content'] = $cipher->encrypt($value, 'card-content');
        $this->attributes['content_fingerprint'] = $cipher->fingerprint($value);
    }

    public static function storageAttributes(array $record): array
    {
        $model = new static;
        $model->forceFill($record);
        // Never trust an incoming fingerprint supplied after content in a bulk row.
        if (isset($record['content'])) {
            $model->attributes['content_fingerprint'] = app(SecretCipher::class)->fingerprint($record['content']);
        }

        return $model->getAttributes();
    }

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeUnsold(Builder $query): Builder
    {
        return $query->where('status', 'unsold');
    }

    public function scopeSold(Builder $query): Builder
    {
        return $query->where('status', 'sold');
    }

    public function scopeLocked(Builder $query): Builder
    {
        return $query->where('status', 'locked');
    }
}
