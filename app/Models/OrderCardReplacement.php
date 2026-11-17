<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderCardReplacement extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['request_token'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function admin(): BelongsTo { return $this->belongsTo(Admin::class); }
    public function items(): HasMany { return $this->hasMany(OrderCardReplacementItem::class, 'replacement_id')->orderBy('id'); }
}
