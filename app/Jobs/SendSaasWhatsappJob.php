<?php

namespace App\Jobs;

use App\Models\SentMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendSaasWhatsappJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageModel;

    public function __construct(SentMessage $messageModel)
    {
        $this->messageModel = $messageModel;
    }

    public function handle(): void
    {
        $user = $this->messageModel->user;
        $settings = $user->whatsappSetting;

        if (! $settings) {
            $this->messageModel->update(['status' => 'failed']);

            return;
        }

        try {
            // طلب إرسال رسمي مباشر لخوادم Meta Cloud API بناءً على بيانات المستخدم
            $response = Http::withToken($settings->meta_token)
                ->post("https://graph.facebook.com/v20.0/{$settings->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $this->messageModel->phone,
                    'type' => 'text',
                    'text' => ['body' => $this->messageModel->message],
                ]);

            if ($response->successful()) {
                $this->messageModel->update(['status' => 'sent']);
            } else {
                $this->messageModel->update(['status' => 'failed']);
            }
        } catch (\Exception $e) {
            $this->messageModel->update(['status' => 'failed']);
        }
    }
}
