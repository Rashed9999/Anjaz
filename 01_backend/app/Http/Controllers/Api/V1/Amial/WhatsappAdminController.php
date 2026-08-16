<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\CentralLogics\OtpDispatcher;
use App\CentralLogics\WhatsappModule;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-WHATSAPP-OTP-001 — إدارة إعدادات قناة واتساب من لوحة الأدمن.
 *
 * Endpoints (تحت /api/v1/amial/admin/whatsapp، محميّة auth:api + صلاحية أدمن):
 *   GET  /config            ← إعدادات كل المزوّدين (الأسرار مُقنّعة) + تفضيل القناة
 *   POST /provider          ← حفظ/تحديث إعداد مزوّد + تفعيله/تعطيله
 *   POST /channel           ← ضبط ترتيب القناة (whatsapp_first/sms_first/...)
 *   POST /test              ← إرسال تجريبي (OTP أو رسالة) للتحقّق من الإعداد
 */
class WhatsappAdminController extends Controller
{
    /**
     * **هذه القائمةُ هي البابُ، لا مجلَّدُ المزوّدين.**
     *
     * `ProviderRegistry` يكتشف الأصنافَ بمسح المجلّد، **لكنّ الحفظَ هنا
     * محكومٌ بـ`in:` على هذه القائمة**. فصنفٌ مبنيٌّ واسمُه ليس فيها
     * يُرفض حفظُه بـ٤٢٢، ولا يُفعَّل أبداً — «مبنيٌّ ولا يُوصَل إليه» في
     * أنقى صوره: الشيفرةُ سليمةٌ والقائمةُ لا تعرفها.
     *
     * الحارس: `MessagingProviderReachabilityTest` — يقارن المجلَّدَ بالقائمة.
     */
    private const PROVIDERS = [
        'meta_cloud', 'twilio', '360dialog', 'wati', 'ultramsg',
        // AMIAL-MESSAGING-002 — غيرُ رسميٍّ بلا نافذةِ ٢٤ ساعة، فيصلح
        // لإنذارات ٠٢:٠٠ التي يمنعها meta_cloud وtwilio.
        'green_api',
    ];
    private const SECRET_KEYS = ['access_token', 'token', 'api_key', 'sid'];
    private const CHANNELS = ['whatsapp_first', 'sms_first', 'whatsapp_only', 'sms_only'];

    /** GET /config — كل المزوّدين بأسرار مُقنّعة + التفضيل الحالي. */
    public function getConfig(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $providers = [];
        foreach (self::PROVIDERS as $name) {
            $row = Setting::where('key_name', $name)->where('settings_type', 'whatsapp_config')->first();
            $values = (array) ($row->live_values ?? []);
            $providers[] = [
                'provider' => $name,
                'enabled' => (int) ($values['status'] ?? 0) === 1,
                'config' => $this->maskSecrets($values),
            ];
        }

        return $this->ok([
            'providers' => $providers,
            'channel_preference' => OtpDispatcher::channelPreference(),
            'channels' => self::CHANNELS,
        ]);
    }

    /** POST /provider — حفظ إعداد مزوّد. body: {provider, status, config:{...}} */
    public function saveProvider(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $v = Validator::make($request->all(), [
            'provider' => ['required', 'string', 'in:' . implode(',', self::PROVIDERS)],
            'status' => ['required', 'boolean'],
            'config' => ['required', 'array'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        $provider = $request->input('provider');
        $status = $request->boolean('status') ? 1 : 0;

        // الأسرار المُقنّعة (••••) لا تُكتب فوق القيمة الحقيقية المحفوظة.
        $existing = (array) (Setting::where('key_name', $provider)
            ->where('settings_type', 'whatsapp_config')->first()->live_values ?? []);
        $incoming = $this->preserveMaskedSecrets($request->input('config', []), $existing);

        // status يُحفظ داخل live_values لأن WhatsappModule يقرأه من هناك.
        $values = array_merge($incoming, ['status' => $status]);

        Setting::updateOrCreate(
            ['key_name' => $provider, 'settings_type' => 'whatsapp_config'],
            [
                'key_name' => $provider,
                'settings_type' => 'whatsapp_config',
                'live_values' => $values,
                'test_values' => $values,
                'mode' => 'live',
                'is_active' => $status,
            ]
        );

        return $this->ok([
            'provider' => $provider,
            'enabled' => $status === 1,
            'config' => $this->maskSecrets($values),
        ], 'SAVED', 'تم حفظ إعداد المزوّد');
    }

    /** POST /channel — ضبط تفضيل القناة. body: {value} */
    public function setChannel(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $v = Validator::make($request->all(), [
            'value' => ['required', 'string', 'in:' . implode(',', self::CHANNELS)],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        Setting::updateOrCreate(
            ['key_name' => 'otp_channel', 'settings_type' => 'otp_config'],
            [
                'key_name' => 'otp_channel',
                'settings_type' => 'otp_config',
                'live_values' => ['value' => $request->input('value')],
                'mode' => 'live',
                'is_active' => 1,
            ]
        );

        return $this->ok(['channel_preference' => $request->input('value')], 'SAVED', 'تم ضبط القناة');
    }

    /** POST /test — إرسال تجريبي. body: {phone, message?} */
    public function testSend(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $v = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'min:6', 'max:20'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        $phone = $request->input('phone');
        if ($request->filled('message')) {
            $result = WhatsappModule::sendText($phone, $request->input('message'));
        } else {
            $result = OtpDispatcher::send($phone, (string) random_int(1000, 9999));
        }

        return $result === 'success'
            ? $this->ok(['result' => $result], 'SENT', 'تم الإرسال بنجاح')
            : $this->error('SEND_FAILED', "فشل الإرسال (result={$result}) — راجع الإعدادات/اللوقات", 422);
    }

    // ============ Helpers ============

    private function maskSecrets(array $values): array
    {
        foreach (self::SECRET_KEYS as $k) {
            if (!empty($values[$k])) {
                $s = (string) $values[$k];
                $values[$k] = strlen($s) > 4 ? '••••' . substr($s, -4) : '••••';
            }
        }
        unset($values['status']); // status يُعرَض عبر enabled لا داخل config
        return $values;
    }

    /** قيمة سرّية مُقنّعة قادمة من الواجهة => أبقِ المحفوظة. */
    private function preserveMaskedSecrets(array $incoming, array $existing): array
    {
        foreach (self::SECRET_KEYS as $k) {
            if (isset($incoming[$k]) && str_starts_with((string) $incoming[$k], '••••') && isset($existing[$k])) {
                $incoming[$k] = $existing[$k];
            }
        }
        return $incoming;
    }

    private function isAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        return (int) ($user->type ?? -1) === 0
            || in_array($user->role, [A::ROLE_ADMIN, 'super_admin'], true); // AMIAL-FIX(C1): لا النوع 1 (وكيل)
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object) [],
        ], 422);
    }
}
