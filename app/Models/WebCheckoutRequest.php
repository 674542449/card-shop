<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebCheckoutRequest extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['owner_hash', 'key_hash', 'request_hash'];
}
