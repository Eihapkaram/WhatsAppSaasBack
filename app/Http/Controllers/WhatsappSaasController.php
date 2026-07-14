<?php

namespace App\Http\Controllers;

use App\Exports\UserReceivedMessagesExport;
use App\Jobs\SendSaasWhatsappJob;
use App\Models\ReceivedMessage;
use App\Models\SentMessage;
use App\Models\WhatsappSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel; // تأكد من استيراد الـ Log في أعلى الملف

class WhatsappSaasController extends Controller
{
    public function saveSettings(Request $request)
    {
        $request->validate([
            'meta_token' => 'required|string',
            'phone_number_id' => 'required|string',
            'verify_token' => 'required|string',
        ]);

        $settings = WhatsappSetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->only(['meta_token', 'phone_number_id', 'verify_token'])
        );

        return response()->json(['message' => 'تم حفظ الإعدادات بنجاح', 'settings' => $settings]);
    }

    // الـ Webhook الموحد للتحقق من ميتا (يتحقق لو الـ Verify Token متواجد بالسيستم لأي عميل)
    public function verifyWebhook(Request $request)
    {
        $hubVerifyToken = $request->input('hub_verify_token');
        $exists = WhatsappSetting::where('verify_token', $hubVerifyToken)->exists();

        if ($request->input('hub_mode') === 'subscribe' && $exists) {
            return response($request->input('hub_challenge'), 200);
        }

        return response('Forbidden', 403);
    }

    // استقبال الرسائل الموحد وتوزيعها تلقائياً على حسابات المستخدمين حسب الرقم المستقبِل
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();

        // 1. Log فوري عند ملامسة الطلب للسيرفر (سيظهر لك في الـ log مهما حدث)
        \Log::info('--- WHATSAPP WEBHOOK CALL RECEIVED ---');
        \Log::info(json_encode($payload));

        // 2. التحقق من وجود مصفوفة الـ entries والتغييرات بشكل مرن
        if (empty($payload['entry'][0]['changes'][0]['value']['messages'])) {
            \Log::warning('Webhook received but it does NOT contain any messages (might be a status update).');

            return response()->json(['status' => 'no_messages_in_payload'], 200);
        }

        $value = $payload['entry'][0]['changes'][0]['value'];
        $metaPhoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        // 3. البحث عن الإعدادات المطابقة
        $settings = WhatsappSetting::where('phone_number_id', $metaPhoneNumberId)->first();

        if (! $settings) {
            \Log::error("No settings found in DB for Phone ID: {$metaPhoneNumberId}");

            return response()->json(['status' => 'settings_not_found'], 200);
        }

        \Log::info("Matching settings found for User ID: {$settings->user_id}");

        // 4. معالجة كل الرسائل الواردة في هذا الطلب
        foreach ($value['messages'] as $messageData) {
            try {
                // الحفظ باستخدام الموديل مباشرة
                $savedMessage = ReceivedMessage::create([
                    'user_id' => $settings->user_id,
                    'phone' => $messageData['from'],
                    'name' => $value['contacts'][0]['profile']['name'] ?? 'غير معروف',
                    'message' => $messageData['text']['body'] ?? '[وسائط]',
                    'received_at' => now(), // أو تحويل الـ timestamp القادم من فيسبوك
                ]);

                \Log::info("SUCCESS: Message saved successfully in DB! Msg ID: {$savedMessage->id}");

            } catch (\Exception $e) {
                \Log::error('DB INSERT FAILED: '.$e->getMessage());
            }
        }

        return response()->json(['status' => 'success'], 200);
    }

    // تكييف الدالة لتستقبل ملف الإكسيل مباشرة من الفرونت إيند وتجدول الحملة ⚡
    public function startCampaign(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls',
            'delay' => 'required|integer|min:1',
        ]);

        $userId = $request->user()->id;
        $delay = intval($request->delay);
        $currentDelay = 0;

        try {
            // تحويل شيت الإكسيل المرفوع مباشرة إلى مصفوفة (Array)
            // نفترض أن العمود الأول (A) هو الرقم والعمود الثاني (B) هو نص الرسالة
            $rows = Excel::toArray([], $request->file('excel_file'))[0];

            // لو الملف فاضي أو يحتوي على العناوين فقط
            if (count($rows) <= 1) {
                return response()->json(['message' => 'ملف الإكسيل فارغ أو غير صالح'], 422);
            }

            // تخطي السطر الأول (Header) والبدء في قراءة البيانات
            foreach (array_slice($rows, 1) as $row) {
                $phone = trim($row[0] ?? '');
                $textMessage = trim($row[1] ?? '');

                // تخطي الأسطر الفارغة داخل الإكسيل
                if (empty($phone) || empty($textMessage)) {
                    continue;
                }

                // 1. تسجيل الرسالة في جدول الـ SentMessages بوضع pending
                $insertedMessage = SentMessage::create([
                    'user_id' => $userId,
                    'phone' => $phone,
                    'message' => $textMessage,
                    'status' => 'pending',
                ]);

                // 2. رمي الـ Job في الـ Queue وتطبيق الـ Delay المتصاعد
                SendSaasWhatsappJob::dispatch($insertedMessage)
                    ->delay(now()->addSeconds($currentDelay));

                // زيادة الـ Delay للرسالة القادمة
                $currentDelay += $delay;
            }

            return response()->json(['message' => 'تم رفع الملف وقراءة البيانات، وجدولة حملتك بنجاح في الطابور!']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'حدث خطأ أثناء معالجة ملف الإكسيل: '.$e->getMessage()], 500);
        }
    }

    public function getReceivedMessages(Request $request)
    {
        return response()->json(
            ReceivedMessage::where('user_id', $request->user()->id)->orderBy('received_at', 'desc')->get()
        );
    }

    public function exportExcel(Request $request)
    {
        return Excel::download(new UserReceivedMessagesExport($request->user()->id), 'my_received_messages.xlsx');
    }
}
