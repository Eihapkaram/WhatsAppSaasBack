<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WhatsappSaasController;
use Illuminate\Support\Facades\Route;

// مسارات المصادقة العامة (Passport)

Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login']);

// مسارات استقبال الـ Webhook العامة من خوادم Meta (بدون حماية لتقرأها فيسبوك)

Route::get('/whatsapp-webhook', [WhatsappSaasController::class, 'verifyWebhook']);

Route::post('/whatsapp-webhook', [WhatsappSaasController::class, 'handleWebhook']);

// مسارات لوحة تحكم التطبيق المحمية بـ Passport Guard 🔥

Route::middleware('auth:api')->group(function () {

    Route::post('/save-settings', [WhatsappSaasController::class, 'saveSettings']);

    Route::post('/start-campaign', [WhatsappSaasController::class, 'startCampaign']);

    Route::get('/received-messages', [WhatsappSaasController::class, 'getReceivedMessages']);

    Route::get('/export-messages', [WhatsappSaasController::class, 'exportExcel']);

});
