<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens; // الاستدعاء هنا 🔥

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    // علاقات النظام المتعدد المستخدمين
    public function whatsappSetting()
    {
        return $this->hasOne(WhatsappSetting::class);
    }

    public function receivedMessages()
    {
        return $this->hasMany(ReceivedMessage::class);
    }

    public function sentMessages()
    {
        return $this->hasMany(SentMessage::class);
    }
}
