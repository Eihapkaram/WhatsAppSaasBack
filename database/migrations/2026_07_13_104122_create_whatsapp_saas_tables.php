<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. جدول إعدادات ميتا الخاصة بكل مستخدم
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('meta_token');
            $table->string('phone_number_id')->unique();
            $table->string('verify_token');
            $table->timestamps();
        });

        // 2. جدول الرسائل المستلمة (مربوط بالمستخدم)
        Schema::create('received_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('phone');
            $table->string('name')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        // 3. جدول حملات الإرسال (مربوط بالمستخدم)
        Schema::create('sent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('batch_id')->nullable()->index(); // 🌟 حقل جديد لتجميع رسائل الحملة الواحدة
            $table->string('phone');
            $table->text('message');
            $table->string('status')->default('pending'); // pending, sent, failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_messages');
        Schema::dropIfExists('received_messages');
        Schema::dropIfExists('whatsapp_settings');
    }
};
