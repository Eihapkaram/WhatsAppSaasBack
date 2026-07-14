<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivedMessage extends Model
{
    protected $fillable = ['user_id', 'phone', 'name', 'message', 'received_at'];

    protected $casts = ['received_at' => 'datetime'];
}
