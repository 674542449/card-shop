<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiOrderRequest extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['key_hash', 'request_hash', 'response_payload'];

    protected function casts(): array
    {
        return ['response_payload' => 'encrypted:array', 'response_status' => 'integer'];
    }
}
