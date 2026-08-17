<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\CentralLogics\SmsModule;
use App\Http\Controllers\Controller;
use App\Models\FeeScheme;
use App\Models\Setting;
use App\Models\User;
use App\Services\FeeService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-SETTINGS-CENTER-001 — مركز الإعدادات الموحّد (لوحة الأدمن في التطبيق).
 *
 * الهدف: إدارة كل ما كان يتطلّب الدخول للباكند/لوحة الويب — من شاشة واحدة بأزرار:
 *   SMS (مزوّدون) · إشعارات واتساب · بيانات التواصل/الدعم · الرسوم/نسب الأرباح.
 * (إعدادات واتساب OTP لها وحدتها الخاصة WhatsappAdminController.)
 *
 * Endpoints تحت /api/v1/amial/admin/settings (auth:api + صلاحية أدمن):
 *   GET  /sms                 ← مزوّدو SMS (الأسرار مُقنّعة)
 *   POST /sms/provider        ← حفظ/تفعيل مزوّد SMS
 *   GET  /notifications       ← حالة إشعارات واتساب (تفعيل + الأنواع)
 *   POST /notifications       ← ضبطها
 *   GET  /contact             ← بيانات التواصل (واتساب/هاتف/إيميل)
 *   POST /contact             ← تحديثها
 *   GET  /fees                ← النسخ النشطة لكل كود رسم
 *   POST /fees                ← إنشاء نسخة رسم جديدة (append-only)
 *   POST /fees/simulate       ← محاكاة رسم قبل الحفظ
 *   POST /fees/{id}/deactivate← تعطيل نسخة
 */
class AdminSettingsController extends AmialApiController // AMIAL-FIX-007
{
    private const SMS_PROVIDERS = ['twilio', 'nexmo', '2factor', 'msg91'];
    private const SMS_FIELDS = [
        'twilio' => ['sid', 'token', 'messaging_service_sid', 'otp_template'],
        'nexmo' => ['api_key', 'api_secret', 'from', 'otp_template'],
        '2factor' => ['api_key'],
        'msg91' => ['auth_key', 'template_id'],
    ];
    private const SECRET_KEYS = ['token', 'api_secret', 'auth_key', 'api_key', 'sid'];

    public function __construct(private readonly FeeService $fees) {}

    // ============================================================
    // SMS
    // ============================================================

    public function getSms(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        $providers = [];
        foreach (self::SMS_PROVIDERS as $name) {
            $values = (array) (Setting::where('key_name', $name)
                ->where('settings_type', 'sms_config')->first()->live_values ?? []);
            $providers[] = [
                'provider' => $name,
                'enabled' => (int) ($values['status'] ?? 0) === 1,
                'fields' => self::SMS_FIELDS[$name],
                'config' => $this->maskSecrets($values),
            ];
        }
        return $this->ok(['providers' => $providers]);
    }

    public function saveSmsProvider(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        $v = Validator::make($request->all(), [
            'provider' => ['required', 'in:' . implode(',', self::SMS_PROVIDERS)],
            'status' => ['required', 'boolean'],
            'config' => ['required', 'array'],
        ]);
        if ($v->fails()) return $this->validationError($v);

        $provider = $request->input('provider');
        $existing = (array) (Setting::where('key_name', $provider)
            ->where('settings_type', 'sms_config')->first()->live_values ?? []);
        $incoming = $this->preserveMaskedSecrets($request->input('config', []), $existing);
        $values = array_merge($incoming, ['status' => $request->boolean('status') ? 1 : 0]);

        Setting::updateOrCreate(
            ['key_name' => $provider, 'settings_type' => 'sms_config'],
            ['key_name' => $provider, 'settings_type' => 'sms_config',
             'live_values' => $values, 'test_values' => $values,
             'mode' => 'live', 'is_active' => $values['status']]
        );

        return $this->ok([
            'provider' => $provider,
            'enabled' => $values['status'] === 1,
            'config' => $this->maskSecrets($values),
        ], 'SAVED', 'تم حفظ مزوّد الرسائل');
    }

    // ============================================================
    // إشعارات واتساب
    // ============================================================

    public function getNotifications(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        $values = (array) (Setting::where('key_name', 'whatsapp_notifications')
            ->where('settings_type', 'whatsapp_config')->first()->live_values ?? []);

        return $this->ok([
            'enabled' => (int) ($values['status'] ?? 0) === 1,
            'types' => $values['types'] ?? 'all',
            'known_types' => \App\Services\NotificationService::TYPES,
        ]);
    }

    public function saveNotifications(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        $v = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'types' => ['nullable'], // 'all' أو مصفوفة أنواع
        ]);
        if ($v->fails()) return $this->validationError($v);

        $types = $request->input('types', 'all');
        if (is_array($types)) $types = array_values(array_filter(array_map('strval', $types)));
        if ($types !== 'all' && !is_array($types)) $types = 'all';

        Setting::updateOrCreate(
            ['key_name' => 'whatsapp_notifications', 'settings_type' => 'whatsapp_config'],
            ['key_name' => 'whatsapp_notifications', 'settings_type' => 'whatsapp_config',
             'live_values' => ['status' => $request->boolean('enabled') ? 1 : 0, 'types' => $types],
             'mode' => 'live', 'is_active' => 1]
        );

        return $this->ok(['enabled' => $request->boolean('enabled'), 'types' => $types], 'SAVED', 'تم حفظ إعداد الإشعارات');
    }

    // ============================================================
    // بيانات التواصل/الدعم
    // ============================================================

    public function getContact(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        return $this->ok(['contact' => self::contactValues()]);
    }

    public function saveContact(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        $v = Validator::make($request->all(), [
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'support_email' => ['nullable', 'email', 'max:191'],
        ]);
        if ($v->fails()) return $this->validationError($v);

        $values = array_merge(self::contactValues(), array_filter([
            'whatsapp_number' => $request->input('whatsapp_number'),
            'phone_number' => $request->input('phone_number'),
            'support_email' => $request->input('support_email'),
        ], fn ($x) => $x !== null));

        Setting::updateOrCreate(
            ['key_name' => 'support_contact', 'settings_type' => 'contact_config'],
            ['key_name' => 'support_contact', 'settings_type' => 'contact_config',
             'live_values' => $values, 'mode' => 'live', 'is_active' => 1]
        );

        return $this->ok(['contact' => $values], 'SAVED', 'تم حفظ بيانات التواصل');
    }

    /** GET /api/v1/amial/support-contact — عام (يقرأه التطبيق ليعرض أزرار الدعم). */
    public function publicContact(): JsonResponse
    {
        return $this->ok(['contact' => self::contactValues()]);
    }

    private static function contactValues(): array
    {
        $row = config_settings('support_contact', 'contact_config');
        $values = $row && $row->live_values ? (array) json_decode($row->live_values, true) : [];
        return array_merge([
            'whatsapp_number' => '967777000000',
            'phone_number' => '+967777000000',
            'support_email' => 'support@amialpay.com',
        ], $values);
    }

    // ============================================================
    // الرسوم/نسب الأرباح (FeeService — append-only)
    // ============================================================

    public function getFees(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        $active = FeeScheme::where('is_active', true)
            ->orderBy('code')->orderByDesc('version')->get()
            ->map(fn ($s) => $s->only([
                'id', 'code', 'label', 'zone_code', 'applies_to', 'fee_type',
                'percent_rate', 'fixed_amount', 'min_fee', 'max_fee',
                'agent_commission_percent', 'agent_commission_fixed',
                'bearer', 'version', 'effective_from',
            ]));

        return $this->ok([
            'schemes' => $active,
            'codes' => FeeScheme::codes(),
            'fee_types' => FeeScheme::FEE_TYPES,
            'bearers' => FeeScheme::BEARERS,
            'applies_to' => FeeScheme::APPLIES_TO,
        ]);
    }

    public function createFee(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        try {
            $scheme = $this->fees->createVersion($request->all(), $request->user()->id, $request->ip());
            return $this->ok(['scheme' => $scheme], 'CREATED', "تم إنشاء النسخة v{$scheme->version}");
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }
    }

    public function simulateFee(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        try {
            $result = $this->fees->simulate($request->input('scheme', []), (string) $request->input('amount', '0'));
            return $this->ok(['simulation' => $result]);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }
    }

    public function deactivateFee(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        try {
            $this->fees->deactivate($id, $request->user()->id, $request->ip());
            return $this->ok([], 'DEACTIVATED', 'تم تعطيل النسخة');
        } catch (\Throwable $e) {
            return $this->error('NOT_FOUND', 'النسخة غير موجودة', 404);
        }
    }

    // ============================================================
    // Helpers (نفس نمط WhatsappAdminController)
    // ============================================================

    private function maskSecrets(array $values): array
    {
        foreach (self::SECRET_KEYS as $k) {
            if (!empty($values[$k])) {
                $s = (string) $values[$k];
                $values[$k] = strlen($s) > 4 ? '••••' . substr($s, -4) : '••••';
            }
        }
        unset($values['status']);
        return $values;
    }

    private function preserveMaskedSecrets(array $incoming, array $existing): array
    {
        foreach (self::SECRET_KEYS as $k) {
            if (isset($incoming[$k]) && str_starts_with((string) $incoming[$k], '••••') && isset($existing[$k])) {
                $incoming[$k] = $existing[$k];
            }
        }
        return $incoming;
    }
}
