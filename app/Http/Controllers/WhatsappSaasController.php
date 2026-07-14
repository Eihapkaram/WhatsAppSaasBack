<?php

namespace App\Http\Controllers;

use App\Exports\UserReceivedMessagesExport;
use App\Jobs\SendSaasWhatsappJob;
use App\Models\ReceivedMessage;
use App\Models\SentMessage;
use App\Models\WhatsappSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

// تأكد من استيراد الـ Log في أعلى الملف

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

        // 1. Log فوري عند ملامسة الطلب للسيرفر
        \Log::info('--- WHATSAPP WEBHOOK CALL RECEIVED ---');
        \Log::info(json_encode($payload));

        // 2. التحقق من وجود مصفوفة الرسائل
        if (empty($payload['entry'][0]['changes'][0]['value']['messages'])) {
            \Log::warning('Webhook received but it does NOT contain any messages (might be a status update).');

            return response()->json(['status' => 'no_messages_in_payload'], 200);
        }

        $value = $payload['entry'][0]['changes'][0]['value'];
        $metaPhoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        // 3. البحث عن الإعدادات المطابقة لربط الرسالة بالمستخدم الصحيح
        $settings = WhatsappSetting::where('phone_number_id', $metaPhoneNumberId)->first();

        if (! $settings) {
            \Log::error("No settings found in DB for Phone ID: {$metaPhoneNumberId}");

            return response()->json(['status' => 'settings_not_found'], 200);
        }

        \Log::info("Matching settings found for User ID: {$settings->user_id}");

        // 🌟 خطوة احترافية: تجهيز مصفوفة الأسماء وربطها بالـ WhatsApp ID الخاص بكل شخص
        $contacts = [];
        if (! empty($value['contacts'])) {
            foreach ($value['contacts'] as $contact) {
                $contacts[$contact['wa_id']] = $contact['profile']['name'] ?? 'غير معروف';
            }
        }

        // 4. معالجة كل الرسائل الواردة بحذر ودقة
        foreach ($value['messages'] as $messageData) {
            try {
                $senderPhone = $messageData['from'];

                // جلب اسم المرسل الدقيق من المصفوفة التي جهزناها، وإلا نضع 'غير معروف'
                $senderName = $contacts[$senderPhone] ?? 'غير معروف';

                // تحويل الـ UNIX Timestamp القادم من Meta إلى Carbon Date ليكون الوقت دقيقاً 100%
                $receivedAt = isset($messageData['timestamp'])
                    ? Carbon::createFromTimestamp($messageData['timestamp'])
                    : now();

                // الحفظ باستخدام الموديل
                $savedMessage = ReceivedMessage::create([
                    'user_id' => $settings->user_id,
                    'phone' => $senderPhone,
                    'name' => $senderName,
                    'message' => $messageData['text']['body'] ?? '[وسائط/نوع آخر]',
                    'received_at' => $receivedAt,
                ]);

                \Log::info("SUCCESS: Message saved successfully in DB! Msg ID: {$savedMessage->id}");

            } catch (\Exception $e) {
                \Log::error('DB INSERT FAILED: '.$e->getMessage());
            }
        }

        return response()->json(['status' => 'success'], 200);
    }

    // تكييف الدالة لتستقبل ملف الإكسيل مباشرة من الفرونت إيند وتجدول الحملة ⚡
    /*public function startCampaign(Request $request)
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
    }*/
    public function startCampaign(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls',
            'delay' => 'required|integer|min:1',
        ]);

        $userId = $request->user()->id;
        $delay = intval($request->delay);
        $currentDelay = 0;

        // 🌟 توليد معرف فريد لهذه الحملة لتتبعها
        $batchId = 'batch_'.time().'_'.uniqid();

        try {
            $rows = Excel::toArray([], $request->file('excel_file'))[0];

            if (count($rows) <= 1) {
                return response()->json(['message' => 'ملف الإكسيل فارغ أو غير صالح'], 422);
            }

            foreach (array_slice($rows, 1) as $row) {
                $phone = trim($row[0] ?? '');
                $textMessage = trim($row[1] ?? '');

                if (empty($phone) || empty($textMessage)) {
                    continue;
                }

                // 🌟 حفظ الـ batch_id مع كل رسالة
                $insertedMessage = SentMessage::create([
                    'user_id' => $userId,
                    'batch_id' => $batchId,
                    'phone' => $phone,
                    'message' => $textMessage,
                    'status' => 'pending',
                ]);

                SendSaasWhatsappJob::dispatch($insertedMessage)
                    ->delay(now()->addSeconds($currentDelay));

                $currentDelay += $delay;
            }

            // 🌟 نرجع الـ batchId للفرونت إيند ليتتبع من خلاله
            return response()->json([
                'message' => 'تم رفع الملف وجدولة حملتك بنجاح!',
                'batch_id' => $batchId,
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'حدث خطأ أثناء معالجة الملف: '.$e->getMessage()], 500);
        }
    }

    public function getCampaignProgress(Request $request, $batchId)
    {
        $userId = $request->user()->id;

        // إجمالي الرسائل المجدولة في هذه الحملة
        $total = SentMessage::where('user_id', $userId)->where('batch_id', $batchId)->count();

        // 🌟 حماية: إذا كان الـ total صفر، فهذا يعني أن البيانات لم تُحفظ بالكامل بعد في قاعدة البيانات.
        // لا نرجع completed: true هنا حتى لا يتم إيقاف الـ Polling في الفرونت إيند فوراً.
        if ($total === 0) {
            return response()->json([
                'progress' => 0,
                'completed' => false, // 👈 جعلناها false عمداً لمنع تصفير التتبع بالبداية
                'processed' => 0,
                'total' => 0,
            ]);
        }

        // الرسائل التي تم الانتهاء من معالجتها (سواء نجحت أو فشلت)
        $processed = SentMessage::where('user_id', $userId)
            ->where('batch_id', $batchId)
            ->whereIn('status', ['sent', 'failed'])
            ->count();

        $progress = round(($processed / $total) * 100);

        return response()->json([
            'progress' => $progress,
            'processed' => $processed,
            'total' => $total,
            'completed' => $processed === $total, // ينتهي فقط عندما يتساوى المعالج مع الكلي الفعلي
        ]);
    }

    public function getSettings(Request $request)
    {
        $settings = WhatsappSetting::where('user_id', $request->user()->id)->first();

        return response()->json($settings ?? [
            'meta_token' => '',
            'phone_number_id' => '',
            'verify_token' => '',
        ]);
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
