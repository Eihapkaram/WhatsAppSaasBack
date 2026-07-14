<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SentMessage extends Model
{
    protected $fillable = ['user_id', 'phone', 'message', 'status', 'batch_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
