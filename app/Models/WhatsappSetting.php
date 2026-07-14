<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSetting extends Model
{
    // أضف السطر ده صراحةً للتأكد من أن لارافل يقرأ الاسم صحيحاً
    protected $table = 'whatsapp_settings';

    protected $fillable = ['user_id', 'meta_token', 'phone_number_id', 'verify_token'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
