<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Whatsapp\WhatsappBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-WA-001 — صفحة إدخال PIN الآمنة للـ WhatsApp Bot.
 *
 * المسار: GET/POST /wa/pin/{token}
 *
 * السيناريو:
 *   1. Bot يُرسِل رابط "https://domain.com/wa/pin/abc123"
 *   2. المستخدم يفتحه في المتصفّح
 *   3. يدخل PIN → POST
 *   4. السيرفر يُنفّذ التحويل + يُخبر الـ Bot
 *   5. Bot يُرسِل "✅ تمّ التحويل" على واتساب
 */
class WhatsappPinController extends Controller
{
    public function __construct(
        private readonly WhatsappBotService $bot
    ) {}

    /** GET /wa/pin/{token} — عرض صفحة الـ PIN */
    public function show(string $token)
    {
        $data = $this->validateToken($token);
        if (!$data) {
            return response()->view('whatsapp.pin_expired', [], 410);
        }

        return response()->view('whatsapp.pin_form', [
            'token' => $token,
        ]);
    }

    /** POST /wa/pin/{token} — معالجة الـ PIN */
    public function submit(Request $request, string $token)
    {
        $request->validate([
            'pin' => 'required|string|min:4|max:6',
        ]);

        // AMIAL-FIX (double-spend): استهلاك التوكن ذرّياً عبر قفل — نقرتان متزامنتان
        // (أو إعادة إرسال POST) لا يمكن أن تُنفّذا العملية مرّتين. الأول يقفل ويستهلك
        // التوكن ثم ينفّذ؛ الثاني ينتظر القفل فيجد التوكن مستهلَكاً → "انتهت الصلاحية".
        $lock = Cache::lock("wa_pin_lock:{$token}", 15);
        if (!$lock->get()) {
            return response()->view('whatsapp.pin_error', [
                'message' => 'عمليتك قيد المعالجة — لا تُعِد الإرسال.',
            ], 429);
        }

        try {
            $data = $this->validateToken($token);
            if (!$data) {
                return response()->view('whatsapp.pin_expired', [], 410);
            }

            $user = User::find($data['user_id']);
            if (!$user) {
                return response()->view('whatsapp.pin_error', [
                    'message' => 'لم يُعثَر على الحساب',
                ], 404);
            }

            // حذف التوكن قبل التنفيذ داخل القفل (one-time use ذرّي)
            Cache::forget("wa_pin_token:{$token}");

            // AMIAL-WA-003 — توزيع التنفيذ حسب purpose المخزَّن مع التوكن
            $purpose = $data['purpose'] ?? 'transfer';
            $result = match ($purpose) {
                'billpay'         => $this->bot->executeBillPayAfterPin($data['phone'], $user, $request->input('pin')),
                'payment_request' => $this->bot->executePaymentRequestAfterPin($data['phone'], $user, $request->input('pin')),
                default           => $this->bot->executeTransferAfterPin($data['phone'], $user, $request->input('pin')),
            };
        } finally {
            $lock->release();
        }

        if ($result['success']) {
            return response()->view('whatsapp.pin_success');
        }

        return response()->view('whatsapp.pin_error', [
            'message' => $result['message'] ?? 'فشلت العملية',
        ], 422);
    }

    // ============ Helpers ============

    private function validateToken(string $token): ?array
    {
        if (strlen($token) < 20) return null;
        return Cache::get("wa_pin_token:{$token}");
    }
}
