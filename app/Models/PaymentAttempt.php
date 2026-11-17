<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['response_payload', 'lease_token'];

    protected function casts(): array
    {
        return ['lease_expires_at' => 'datetime', 'response_payload' => 'encrypted:array'];
    }
}
