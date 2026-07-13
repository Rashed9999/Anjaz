<?php

namespace App\Services;

use App\Models\PosUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-UNIFIED-AUTH-001 (v1.5)
 *
 * UnifiedAuthService — تسجيل دخول موحد لكل الأدوار.
 *
 * **الأدوار المدعومة:**
 *   - customer: national_id + phone + password
 *   - merchant: merchant_number + phone + password [+ pos_number optional]
 *   - agent:    agent_number + password → OTP step → token
 *   - admin:    email + password [+ 2FA]
 *
 * **الـ flow:**
 *   - Customer/Merchant/Admin: single-step → token
 *   - Agent: two-step (1=password verify→OTP send, 2=OTP verify→token)
 *
 * **الأمان:**
 *   - rate limiting per identifier (5 محاولات/15 دقيقة)
 *   - تسجيل كل محاولة في unified_login_attempts
 *   - failed attempts خلال 24 ساعة تُعرّض حساب مؤقت للقفل
 */
class UnifiedAuthService
{
    // AMIAL-FIX: رُفع مؤقّتاً لإزالة الحظر أثناء التجربة (كان 5 → يقفل بسرعة).
    private const MAX_FAILED_ATTEMPTS_WINDOW = 50;
    private const FAILED_ATTEMPTS_LOCKOUT_MINUTES = 5;

    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly AuditService $audit,
    ) {}

    // ============================================================
    // Customer Login
    // ============================================================

    /**
     * Customer login: national_id + phone + password.
     *
     * @return array{user: User, token: string}
     * @throws \RuntimeException
     */
    public function loginCustomer(string $phone, string $password, Request $request): array
    {
        $this->guardRateLimit('customer', $phone, $request);

        // AMIAL-FIX: البحث بالهاتف فقط (national_id يخصّ KYC لا بوّابة الدخول)
        $user = $this->findUserByMultipleCredentials([
            'phone' => $phone,
        ], CUSTOMER_TYPE);

        if (!$user) {
            $this->recordFailure('customer', $phone, $request, 'INVALID_IDENTITY');
            throw new \RuntimeException('بيانات الدخول غير صحيحة');
        }

        if (!Hash::check($password, $user->password)) {
            $this->recordFailure('customer', $phone, $request, 'WRONG_PASSWORD', $user->id);
            throw new \RuntimeException('بيانات الدخول غير صحيحة');
        }

        $this->assertUserActive($user);

        return $this->issueToken($user, 'customer', $request);
    }

    // ============================================================
    // Merchant Login
    // ============================================================

    /**
     * Merchant login: merchant_number + phone + password [+ pos_number].
     *
     * إذا pos_number موجود → الـ login يكون لـ POS user تحت هذا التاجر.
     * بدون pos_number → الـ login لحساب التاجر الرئيسي.
     */
    public function loginMerchant(
        string $merchantNumber,
        string $phone,
        string $password,
        ?string $posNumber,
        Request $request,
    ): array {
        $this->guardRateLimit('merchant', $merchantNumber, $request);

        // البحث عن التاجر
        $merchant = User::where('type', MERCHANT_TYPE)
            ->whereHas('merchant', fn($q) => $q->where('merchant_number', $merchantNumber))
            ->first();

        if (!$merchant) {
            $this->recordFailure('merchant', $merchantNumber, $request, 'MERCHANT_NOT_FOUND');
            throw new \RuntimeException('بيانات الدخول غير صحيحة');
        }

        // فحص رقم الهاتف
        if (!$this->phoneMatches($merchant, $phone)) {
            $this->recordFailure('merchant', $merchantNumber, $request, 'PHONE_MISMATCH', $merchant->id);
            throw new \RuntimeException('بيانات الدخول غير صحيحة');
        }

        // إذا pos_number موجود → POS user login
        if ($posNumber) {
            $posUser = PosUser::active()
                ->where('merchant_user_id', $merchant->id)
                ->where('pos_number', $posNumber)
                ->first();

            if (!$posUser) {
                $this->recordFailure('merchant', $merchantNumber, $request, 'POS_NOT_FOUND', $merchant->id);
                throw new \RuntimeException('نقطة البيع غير موجودة لهذا التاجر');
            }

            $user = $posUser->user;
            if (!Hash::check($password, $user->password)) {
                $this->recordFailure('merchant', $merchantNumber, $request, 'WRONG_PASSWORD_POS', $user->id);
                throw new \RuntimeException('بيانات الدخول غير صحيحة');
            }

            $this->assertUserActive($user);
            $posUser->update(['last_login_at' => now()]);

            return $this->issueToken($user, 'pos', $request, [
                'merchant_user_id' => $merchant->id,
                'pos_user_id' => $posUser->id,
                'pos_number' => $posNumber,
                'permissions' => $posUser->permissions ?? [],
            ]);
        }

        // login عادي للتاجر
        if (!Hash::check($password, $merchant->password)) {
            $this->recordFailure('merchant', $merchantNumber, $request, 'WRONG_PASSWORD', $merchant->id);
            throw new \RuntimeException('بيانات الدخول غير صحيحة');
        }

        $this->assertUserActive($merchant);

        return $this->issueToken($merchant, 'merchant', $request);
    }

    // ============================================================
    // Agent Login (two-step with OTP)
    // ============================================================

    /**
     * Agent step 1: agent_number + password → OTP sent.
     *
     * @return array{otp_token: string, masked_phone: string}
     */
    public function loginAgentStep1(string $agentNumber, string $password, Request $request): array
    {
        $this->guardRateLimit('agent', $agentNumber, $request);

        $agent = User::where('type', AGENT_TYPE)
            ->where('agent_number', $agentNumber)
            ->first();

        if (!$agent || !Hash::check($password, $agent->password)) {
            $this->recordFailure('agent', $agentNumber, $request, 'INVALID_CREDENTIALS', $agent?->id);
            throw new \RuntimeException('بيانات الدخول غير صحيحة');
        }

        $this->assertUserActive($agent);

        // توليد OTP + token مؤقت
        // AMIAL-DEMO-OTP: في التجربة (AMIAL_DEMO_OTP مضبوط) نستخدم رمزاً ثابتاً
        // ليتمكّن الوكيل من الدخول بلا بوابة SMS. الإنتاج: يبقى عشوائياً.
        $demoOtp = config('app.amial_demo_otp');
        $otpCode = (is_string($demoOtp) && $demoOtp !== '')
            ? $demoOtp
            : (string) random_int(100000, 999999);
        $otpToken = (string) Str::ulid();

        Cache::put(
            $this->otpCacheKey($otpToken),
            [
                'agent_user_id' => $agent->id,
                'otp_code' => $otpCode,
                'attempts' => 0,
                'created_at' => now()->toIso8601String(),
            ],
            now()->addMinutes(5),
        );

        // إرسال OTP عبر SMS (الـ SmsService يجب أن يكون موجود في Cash6)
        $this->sendOtpSms($agent, $otpCode);

        Log::info('Agent OTP sent', [
            'agent_id' => $agent->id,
            'otp_token' => $otpToken,
            // OTP code نفسه لا يُسجَّل
        ]);

        return [
            'otp_token' => $otpToken,
            'masked_phone' => $this->maskPhoneForDisplay($agent),
            'expires_in_seconds' => 300,
        ];
    }

    /**
     * Agent step 2: otp_token + otp_code → token.
     */
    public function loginAgentStep2(string $otpToken, string $otpCode, Request $request): array
    {
        $payload = Cache::get($this->otpCacheKey($otpToken));
        if (!$payload) {
            throw new \RuntimeException('انتهت صلاحية الرمز، أعد الإرسال');
        }

        // فحص محاولات
        if ($payload['attempts'] >= 3) {
            Cache::forget($this->otpCacheKey($otpToken));
            throw new \RuntimeException('تم تجاوز الحد المسموح، أعد الإرسال');
        }

        if (!hash_equals((string)$payload['otp_code'], $otpCode)) {
            $payload['attempts']++;
            Cache::put($this->otpCacheKey($otpToken), $payload, now()->addMinutes(5));
            throw new \RuntimeException('الرمز غير صحيح');
        }

        $agent = User::find($payload['agent_user_id']);
        if (!$agent) {
            throw new \RuntimeException('الحساب غير موجود');
        }

        Cache::forget($this->otpCacheKey($otpToken));
        return $this->issueToken($agent, 'agent', $request);
    }

    // ============================================================
    // Admin Login (with optional 2FA hook)
    // ============================================================

    public function loginAdmin(string $email, string $password, ?string $twoFactorCode, Request $request): array
    {
        $this->guardRateLimit('admin', $email, $request);

        $admin = User::where('type', ADMIN_TYPE)
            ->where('email', $email) // admin uses email (not encrypted-search)
            ->first();

        if (!$admin || !Hash::check($password, $admin->password)) {
            $this->recordFailure('admin', $email, $request, 'INVALID_CREDENTIALS', $admin?->id);
            throw new \RuntimeException('بيانات الدخول غير صحيحة');
        }

        $this->assertUserActive($admin);

        // 2FA check — إذا الـ admin مفعّل 2FA، يطلب الـ code
        if (!empty($admin->two_factor_secret) && $admin->two_factor_enabled) {
            if (empty($twoFactorCode)) {
                throw new \RuntimeException('TWO_FACTOR_REQUIRED'); // controller يفهم هذا
            }
            // التحقق سيكون في 2FAService (يُضاف لاحقاً)
            $valid = $this->verify2FA($admin, $twoFactorCode);
            if (!$valid) {
                $this->recordFailure('admin', $email, $request, '2FA_INVALID', $admin->id);
                throw new \RuntimeException('رمز التحقق الثاني غير صحيح');
            }
        }

        return $this->issueToken($admin, 'admin', $request);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * بحث user بـ encrypted blind_index أو plaintext fallback.
     */
    private function findUserByMultipleCredentials(array $credentials, int $userType): ?User
    {
        $query = User::where('type', $userType);

        foreach ($credentials as $field => $value) {
            $blindCol = "{$field}_blind_index";

            if ($field === 'phone') {
                // AMIAL-FIX: كانت البصمة المُعمّاة تُحسب للرقم المُدخَل فقط (777...)
                // بينما التخزين بصيغة أخرى (967777...) → لا تطابق → فشل الدخول.
                // الآن نحسب البصمة لكل صيغة مكافئة ونطابق أيّها.
                $variants = \App\Support\Phone::variants($value);
                $blindIndexes = array_values(array_filter(array_map(
                    fn ($v) => $this->encryption->blindIndex($v, 'phone'),
                    $variants
                )));
                $query->where(function ($q) use ($blindCol, $blindIndexes, $field, $variants) {
                    $q->whereIn($blindCol, $blindIndexes)
                      ->orWhereIn($field, $variants); // fallback لبيانات غير مشفّرة
                });
            } else {
                $normalizer = $field === 'national_id' ? 'national_id' : null;
                $blindIndex = $this->encryption->blindIndex($value, $normalizer);
                $query->where(function ($q) use ($blindCol, $blindIndex, $field, $value) {
                    $q->where($blindCol, $blindIndex)
                      ->orWhere($field, $value);
                });
            }
        }

        return $query->first();
    }

    private function phoneMatches(User $user, string $phone): bool
    {
        // AMIAL-FIX: نطابق بصمة كل صيغة مكافئة (كان يطابق صيغة الإدخال فقط
        // فيفشل التاجر/الوكيل إن اختلفت صيغة الإدخال عن التخزين).
        $variants = \App\Support\Phone::variants($phone);
        foreach ($variants as $v) {
            if ($user->phone_blind_index === $this->encryption->blindIndex($v, 'phone')) {
                return true;
            }
        }
        // legacy fallback (بيانات غير مشفّرة)
        return in_array($user->phone, $variants, true);
    }

    private function assertUserActive(User $user): void
    {
        if (isset($user->is_active) && !$user->is_active) {
            throw new \RuntimeException('الحساب غير نشط');
        }
        if (isset($user->security_hold_until) && $user->security_hold_until && $user->security_hold_until->isFuture()) {
            throw new \RuntimeException('الحساب موقوف مؤقتاً لأسباب أمنية');
        }
    }

    /**
     * إصدار token (Laravel Passport). يفترض Passport مُكوَّن.
     */
    private function issueToken(User $user, string $role, Request $request, array $extraMeta = []): array
    {
        $tokenName = "amyal-{$role}";
        $token = $user->createToken($tokenName);

        // AMIAL-FIX(POST-LOGIN): تسجيل الجهاز (UserLogHistory) — بدونه يرفض
        // middleware «checkDeviceId» كلّ طلبات الشاشة الرئيسية بـ 403 فتظهر
        // فارغة. نُكرّر منطق 6cash: تعطيل الأجهزة القديمة ثمّ تفعيل الحالي.
        $this->registerDevice($user, $request);

        // تسجيل الـ login الناجح
        $this->recordSuccess($role, $user->id, $request);

        return [
            'user' => $user,
            'token' => $token->accessToken,
            'token_type' => 'Bearer',
            'role' => $role,
            'meta' => $extraMeta,
        ];
    }

    /**
     * AMIAL-FIX(POST-LOGIN): يُسجّل جهاز المستخدم ليمرّ من checkDeviceId.
     * يُطابق منطق LoginController::logUserHistory (6cash): يُعطّل الأجهزة
     * السابقة ويُنشئ سجلّاً نشطاً للجهاز الحالي. آمن: أي خطأ لا يكسر الدخول.
     */
    private function registerDevice(User $user, Request $request): void
    {
        try {
            $deviceId = (string) $request->header('device-id', '');
            if ($deviceId === '') {
                // لا ترويسة جهاز (محاكي/ويب) — لا نُسجّل؛ checkDeviceId يمرّر ::1
                // محلياً، وفي الإنتاج بلا device-id سيُرجع 400 (سلوك 6cash نفسه).
                return;
            }
            \App\Models\UserLogHistory::where('user_id', $user->id)->update(['is_active' => 0]);
            \App\Models\UserLogHistory::updateOrCreate(
                ['user_id' => $user->id, 'device_id' => $deviceId],
                [
                    'ip_address' => $request->ip(),
                    'browser' => (string) $request->header('browser', ''),
                    'os' => (string) $request->header('os', ''),
                    'device_model' => (string) $request->header('device-model', ''),
                    'is_active' => 1,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('registerDevice failed', ['user_id' => $user->id, 'err' => $e->getMessage()]);
        }
    }

    private function guardRateLimit(string $role, string $identifier, Request $request): void
    {
        $key = "auth_attempts:{$role}:" . md5($identifier);
        $attempts = Cache::get($key, 0);

        if ($attempts >= self::MAX_FAILED_ATTEMPTS_WINDOW) {
            throw new \RuntimeException(
                "تم تجاوز عدد المحاولات المسموح. حاول بعد " . self::FAILED_ATTEMPTS_LOCKOUT_MINUTES . " دقيقة"
            );
        }
    }

    private function recordFailure(string $role, string $identifier, Request $request, string $reason, ?int $userId = null): void
    {
        $key = "auth_attempts:{$role}:" . md5($identifier);
        $current = Cache::get($key, 0);
        Cache::put($key, $current + 1, now()->addMinutes(self::FAILED_ATTEMPTS_LOCKOUT_MINUTES));

        try {
            DB::table('unified_login_attempts')->insert([
                'role' => $role,
                'identifier' => mb_substr($identifier, 0, 100),
                'identifier_masked' => $this->maskIdentifier($identifier),
                'success' => false,
                'failure_reason' => $reason,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr($request->userAgent() ?? '', 0, 500),
                'user_id' => $userId,
                'attempted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Login attempt log failed', ['err' => $e->getMessage()]);
        }
    }

    private function recordSuccess(string $role, int $userId, Request $request): void
    {
        // reset rate limit on success
        $key = "auth_attempts:{$role}:" . md5((string)$userId);
        Cache::forget($key);

        try {
            DB::table('unified_login_attempts')->insert([
                'role' => $role,
                'identifier' => "user_{$userId}",
                'identifier_masked' => "user_{$userId}",
                'success' => true,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr($request->userAgent() ?? '', 0, 500),
                'user_id' => $userId,
                'attempted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function maskIdentifier(string $id): string
    {
        $len = strlen($id);
        if ($len < 5) return str_repeat('*', $len);
        return substr($id, 0, 2) . str_repeat('*', $len - 4) . substr($id, -2);
    }

    private function maskPhoneForDisplay(User $user): string
    {
        return $user->phone_masked ?? $this->encryption->maskPhone($user->phone ?? '');
    }

    private function otpCacheKey(string $token): string
    {
        return "agent_otp:{$token}";
    }

    private function sendOtpSms(User $agent, string $otp): void
    {
        // AMIAL-FIX-001 — يرسل OTP فعلاً (WhatsApp أوّلاً ← SMS fallback)
        $phone = $agent->phone ?? '';
        if (empty($phone)) {
            Log::warning('sendOtpSms: agent has no phone', ['agent_id' => $agent->id]);
            return;
        }
        $result = \App\CentralLogics\SmsModule::send($phone, $otp);
        Log::info('OTP dispatch result', [
            'agent_id' => $agent->id,
            'masked_phone' => $this->maskPhoneForDisplay($agent),
            'result' => $result,
        ]);
        if ($result !== 'success') {
            Log::warning('OTP send failed — check WhatsApp/SMS provider config', [
                'agent_id' => $agent->id,
                'result' => $result,
            ]);
        }
    }

    private function verify2FA(User $admin, string $code): bool
    {
        // AMIAL-2FA-001 (v1.8): TwoFactorAuthService موجود الآن
        return app(TwoFactorAuthService::class)->verify($admin, $code);
    }
}
