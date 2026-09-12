<?php

namespace App\Services;

use App\Support\YemenGovernorates;
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
    // AMIAL-SEC-LOGIN-001: قفل مؤقّت بعد 5 محاولات فاشلة لمدة دقيقة (كما هو
    // معيار المحافظ). عند بلوغ الحدّ: يُسجَّل حدث في سجلّ التدقيق ويُرسَل إشعار
    // أمني للمستخدم. (كان مرفوعاً إلى 50 أثناء التجربة — أُعيد للسلوك الآمن.)
    private const MAX_FAILED_ATTEMPTS_WINDOW = 5;
    private const FAILED_ATTEMPTS_LOCKOUT_MINUTES = 1;

    /**
     * AMIAL-SEC-LOGIN-002 — **قفلٌ متصاعد، لأنّ الثابتَ يُتلِف نفسَه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ما كان، وقد حُسب لا خُمّن:**
     *
     *   خمسُ محاولاتٍ ثمّ قفلُ **دقيقةٍ واحدة**، والعدّادُ ينتهي معها
     *   ⇒ المهاجمُ يعود من الصفر كلَّ دقيقة
     *   ⇒ 5 × 60 × 24 = **7200 محاولةٍ يوميّاً** على حسابٍ واحد
     *   ورمزُ PIN أربعةُ أرقامٍ ⇒ فضاؤه 10000 ⇒ **يُكسَر في ~33 ساعة**
     *
     * ومسارُ الدخول الموحّد — وهو الحيُّ الذي يستعمله التطبيق — **بلا
     * حدِّ معدّلٍ على الـIP إطلاقاً**، فلا شيءَ يبطّئ الحجم.
     *
     * ══════════════════════════════════════════════════════════════════
     * **والتصاعدُ لا التشديدُ المسطَّح.**
     *
     * قفلٌ طويلٌ من أوّل خطأ يشلّ من أخطأ في رمزه — وهو الأكثرُ وقوعاً
     * بفارقٍ كبير. **وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.**
     *
     * فالأولى دقيقةٌ (تحتمل الخطأ)، والثانيةُ ربعُ ساعة، والثالثةُ ساعة،
     * وما بعدها أربع. **والعدّادُ الطويلُ يعيش أطولَ من القفل** — فلا
     * يعود المهاجمُ من الصفر بانتظار انقضائه.
     *
     * والحسابُ بعدها: 5 + 5 + 5 + 5×6 = 50 محاولةً يوميّاً ⇒ **الفضاءُ
     * نفسُه يحتاج أكثر من خمس سنوات**.
     */
    private const LOCKOUT_LADDER_MINUTES = [1, 15, 60, 240];

    /** **ذاكرةُ التصعيد تعيش يوماً** — فمن عاد غداً يبدأ من الدرجة الأولى. */
    private const LOCKOUT_MEMORY_MINUTES = 1440;

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

        return $this->issueToken($user, 'customer', $request, [], $phone);
    }

    // ============================================================
    // Merchant Login
    // ============================================================

    /**
     * Merchant login: merchant_number + phone + password [+ employee_code].
     *
     * إذا employee_code موجود → دخول حساب موظف تحت هذا التاجر.
     * بدونه → الدخول لحساب التاجر الرئيسي.
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

        // رمز الموظف يحدد حساب الموظف، لا جهاز نقطة البيع.
        if ($posNumber) {
            $posUser = PosUser::active()
                ->where('merchant_user_id', $merchant->id)
                ->where('pos_number', $posNumber)
                ->first();

            if (!$posUser) {
                $this->recordFailure('merchant', $merchantNumber, $request, 'POS_NOT_FOUND', $merchant->id);
                throw new \RuntimeException('رمز الموظف غير موجود أو الحساب غير مفعّل لهذا التاجر');
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
                'employee_code' => $posNumber,
                'permissions' => $posUser->permissions ?? [],
            ], $merchantNumber);
        }

        // login عادي للتاجر
        if (!Hash::check($password, $merchant->password)) {
            $this->recordFailure('merchant', $merchantNumber, $request, 'WRONG_PASSWORD', $merchant->id);
            throw new \RuntimeException('بيانات الدخول غير صحيحة');
        }

        $this->assertUserActive($merchant);

        return $this->issueToken($merchant, 'merchant', $request, [], $merchantNumber);
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
        // AMIAL-OTP-SPLIT-001: وكيلُ العرض وحده يأخذ الرمزَ الثابت.
        //
        // كان أيُّ وكيلٍ يدخل بـ`123456` ما دام المتغيّر مضبوطاً — وحسابُ
        // الوكيل يملك سيولةً وصلاحيّةَ صرف. صار الرقمُ يحدّد الطريق.
        $otpCode = app(\App\Services\Otp\OtpPolicy::class)->codeFor((string) $agent->phone);
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
        return $this->issueToken($agent, 'agent', $request, [], $agent->agent_number ?? null);
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

        return $this->issueToken($admin, 'admin', $request, [], $email);
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
    private function issueToken(User $user, string $role, Request $request, array $extraMeta = [], ?string $identifier = null): array
    {
        $tokenName = "amial-{$role}";
        $token = $user->createToken($tokenName);

        // AMIAL-POS-DEVICES-003 — **الرمزُ يُربط بمقعده عند الإصدار.**
        //
        // ولو تُرك الربطُ لأوّل طلبٍ لصار الجهازُ يُثبت نفسَه بترويسةٍ
        // يرسلها هو — وهذا **بابُ الالتفاف عينُه**: من نسخ الرمزَ ينسخ
        // الترويسة. فالربطُ يقع هنا، حيث كلمةُ المرور قد فُحصت للتوّ.
        if ($role === 'pos') {
            $this->bindPosDevice(
                $user,
                $token->token->id ?? null,
                $request,
                (int) ($extraMeta['merchant_user_id'] ?? 0),
            );
        }

        // AMIAL-FIX(POST-LOGIN): تسجيل الجهاز (UserLogHistory) — بدونه يرفض
        // middleware «checkDeviceId» كلّ طلبات الشاشة الرئيسية بـ 403 فتظهر
        // فارغة. نُكرّر منطق 6cash: تعطيل الأجهزة القديمة ثمّ تفعيل الحالي.
        $this->registerDevice($user, $request);

        // AMIAL-SEC-LOGIN-001: التقاط «آخر تسجيل دخول» السابق قبل تسجيل الحالي،
        // ليعرضه التطبيق للمستخدم (شعور بالأمان + كشف أي دخول غير مصرّح).
        $lastLogin = $this->previousLogin($user->id);

        // تسجيل الـ login الناجح (+ إعادة تعيين عدّاد المحاولات بالمفتاح الصحيح)
        $this->recordSuccess($role, $user->id, $request, $identifier);

        if ($lastLogin !== null) {
            // AMIAL-ZONE-LABEL-001: «الموقع» من نظام المناطق الجاهز بدل
            // GeoIP — منطقة الحساب مستقرّة ومعيّنة عند KYC.
            //
            // AMIAL-COVERAGE-002: تُفضَّل المحافظة على اسم المنطقة. «الجنوب»
            // يشمل ثماني محافظات، فلا يُميّز دخولاً مشروعاً من آخر مشبوه —
            // وهذا هو الغرض كلّه من عرض آخر دخول. أما «عدن» فيعرف صاحبها
            // فوراً إن كان هو أم لا.
            $governorate = YemenGovernorates::name(
                YemenGovernorates::codeFromName((string) ($user->residence_governorate ?? ''))
            );

            $lastLogin['zone'] = $governorate
                ?? \App\Services\ZonePolicyService::zoneNameAr($user->zone_code ?? null);

            $extraMeta['last_login'] = $lastLogin;
        }

        return [
            'user' => $user,
            'token' => $token->accessToken,
            'token_type' => 'Bearer',
            'role' => $role,
            'meta' => $extraMeta,
        ];
    }

    /**
     * AMIAL-SEC-LOGIN-001: آخر تسجيل دخول ناجح سابق للمستخدم (وقت + IP).
     * تُقرأ من unified_login_attempts قبل إدراج الدخول الحالي. أيّ خطأ → null.
     */
    private function previousLogin(int $userId): ?array
    {
        try {
            $row = DB::table('unified_login_attempts')
                ->where('user_id', $userId)
                ->where('success', true)
                ->orderByDesc('attempted_at')
                ->first();
            if (!$row) return null;
            return [
                'at' => (string) $row->attempted_at,
                'ip' => (string) ($row->ip_address ?? ''),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * AMIAL-FIX(POST-LOGIN): يُسجّل جهاز المستخدم ليمرّ من checkDeviceId.
     * يُطابق منطق LoginController::logUserHistory (6cash): يُعطّل الأجهزة
     * السابقة ويُنشئ سجلّاً نشطاً للجهاز الحالي. آمن: أي خطأ لا يكسر الدخول.
     */
    /**
     * AMIAL-POS-DEVICES-003 — **يربط رمزَ نقطة البيع بمقعد جهازٍ مسجَّل.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا يُخلط بـ`registerDevice` أدناه.** ذاك `UserLogHistory`:
     * **جهازُ جلسةٍ لمستخدمٍ واحد** — يمنع أن يعمل حسابٌ على هاتفين معاً،
     * ويُحظر عند السرقة. وهذا **مقعدُ ترخيصٍ يملكه التاجر** ويتناوب عليه
     * موظّفوه. مفهومان لا يُغني أحدُهما عن الآخر:
     *
     *   `UserLogHistory` : «هل هذا هاتفُ فلانٍ النشط؟»       — أمنُ حساب
     *   `PosDevice`      : «هل هذا مقعدٌ مدفوعٌ في الباقة؟»  — حدُّ باقة
     *
     * ══════════════════════════════════════════════════════════════════
     * **وثلاثةُ فروقٍ في المعاملة، ولكلٍّ سببُه:**
     *
     * ① **ترويسةٌ حاضرةٌ تُفحص دائماً — ولا شفاعةَ للوضع الصامت.**
     *    فمن أرسل معرِّفاً غيرَ مسجَّلٍ ادّعى ادّعاءً، ورفضُه لا يمسّ أحداً
     *    اليوم: لا عميلَ يُرسل هذه الترويسة بعد. (القيدُ الثامن: «دخولٌ
     *    بمعرِّفٍ مخترَع».)
     *
     * ② **وغيابُها هو الصامتُ وحدَه** — لأنّ منعَه اليومَ يُخرج كلَّ موظّفٍ
     *    يعمل الآن. والسببُ وشرطُ الخروج في `config/amial.php`.
     *
     * ③ **ولا يُنشئ مقعداً.** التسجيلُ فعلٌ مستقلٌّ يمرّ بالحصّة والملكيّة،
     *    ولو أنشأ الدخولُ مقعداً لصار **بابَ تسجيلٍ بلا حدّ** (القيدُ الرابع).
     */
    private function bindPosDevice(User $user, $tokenId, Request $request, int $merchantUserId): void
    {
        $header = \App\Http\Middleware\EnsurePosDevice::HEADER;
        $raw = trim((string) $request->header($header, ''));
        $enforced = (bool) config('amial.pos_devices.enforce_session_binding', false);

        if ($raw === '') {
            if ($enforced) {
                throw new \RuntimeException(
                    'حساب الموظف صحيح، لكن جهاز نقطة البيع غير مفعّل. '
                    . 'يسجّل مالك التاجر هذا الجهاز من «أجهزة نقاط البيع» ثم يعيد الموظف الدخول.');
            }

            app(OpsAlertService::class)->note(
                'pos_device_login_without_device',
                'دخولُ نقطة بيعٍ بلا هويّة جهاز',
                sprintf('user=%d · merchant=%d — الوضعُ الصامت', $user->id, $merchantUserId),
            );

            return;
        }

        // ① ترويسةٌ حضرت — فتُفحص، صامتاً كان الوضعُ أو منفَّذاً.
        if ($merchantUserId <= 0) {
            throw new \RuntimeException('تعذّر تحديدُ التاجر لهذه الجلسة');
        }

        try {
            [$device] = \App\Models\Merchant\PosDevice::locate($merchantUserId, $raw);
        } catch (\Throwable $e) {
            // مفتاحُ البصمة غيرُ مضبوطٍ مثلاً — **يُرفع عطلاً ولا يُسرَّب
            // سببُه إلى العميل**، ولا يُقبل الدخولُ بجهازٍ لم يُتحقَّق منه.
            Log::error('bindPosDevice failed', ['user_id' => $user->id, 'err' => $e->getMessage()]);

            throw new \RuntimeException('تعذّر التحقّقُ من الجهاز');
        }

        if ($device === null) {
            throw new \RuntimeException(
                'هذا جهاز نقطة البيع غير مفعّل لهذا التاجر. '
                . 'يسجّله مالك التاجر من «أجهزة نقاط البيع»؛ لا يُنشئ الموظف حساباً جديداً.');
        }

        if ($device->revoked_at !== null || ! $device->is_active) {
            throw new \RuntimeException('أُلغي هذا الجهاز. راجع صاحبَ الحساب.');
        }

        if ($tokenId === null) {
            throw new \RuntimeException('تعذّر ربطُ الجلسة بالجهاز');
        }

        \App\Models\Merchant\PosDeviceSession::create([
            'access_token_id' => (string) $tokenId,
            'pos_device_id' => $device->id,
            'merchant_user_id' => $merchantUserId,
            'actor_user_id' => $user->id,
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);

        $device->forceFill(['last_seen_at' => now()])->save();
    }

    private function registerDevice(User $user, Request $request): void
    {
        try {
            $deviceId = (string) $request->header('device-id', '');
            if ($deviceId === '') {
                // لا ترويسة جهاز (محاكي/ويب) — لا نُسجّل؛ checkDeviceId يمرّر ::1
                // محلياً، وفي الإنتاج بلا device-id سيُرجع 400 (سلوك 6cash نفسه).
                return;
            }
            // AMIAL-DEVICE-TRUST-001: الجهاز المحظور لا يُعاد تنشيطه بالدخول.
            //
            // بلا هذا الشرط يكفي تسجيل دخولٍ ليعود `is_active = 1` على جهازٍ
            // حظره الدعم. صحيحٌ أن `CheckDeviceId` سيمنعه بعد ذلك، لكن حالة
            // الصفّ تصير كاذبة: يقول «نشط» عن جهازٍ ممنوع، فيقرأ الدعمُ
            // شاشةً تناقض ما فعله بيده.
            $existing = \App\Models\UserLogHistory::where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->first();

            if ($existing && $existing->is_blocked) {
                Log::warning('Blocked device attempted login', [
                    'user_id' => $user->id, 'device_id' => $deviceId,
                ]);

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
                    'app_version' => (string) $request->header('app-version', '') ?: null,
                    'last_seen_at' => now(),
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
        $lockKey = "auth_locked:{$role}:" . md5($identifier);

        // **والقفلُ يُقرأ قبل العدّاد** — فمن قُفل لا يزيد عدّادَه بمحاولةٍ
        // مرفوضةٍ أصلاً، وإلّا صعّد المهاجمُ نفسَه إلى الأبد بلا فائدة.
        $until = Cache::get($lockKey);

        if ($until !== null && now()->lt($until)) {
            $left = max(1, (int) ceil(now()->diffInSeconds($until) / 60));

            throw new \RuntimeException(
                "تم تجاوز عدد المحاولات المسموح. حاول بعد {$left} دقيقة"
            );
        }

        if ((int) Cache::get($key, 0) >= self::MAX_FAILED_ATTEMPTS_WINDOW) {
            throw new \RuntimeException(
                'تم تجاوز عدد المحاولات المسموح. حاول بعد قليل'
            );
        }
    }

    private function recordFailure(string $role, string $identifier, Request $request, string $reason, ?int $userId = null): void
    {
        $key = "auth_attempts:{$role}:" . md5($identifier);
        $lockKey = "auth_locked:{$role}:" . md5($identifier);
        $stepKey = "auth_lock_step:{$role}:" . md5($identifier);

        $current = (int) Cache::get($key, 0);
        $newCount = $current + 1;

        // **والعدّادُ يعيش أطولَ من القفل** — وإلّا عاد المهاجمُ من الصفر
        // بانتظار انقضائه، وهو ما جعل القفلَ القديمَ يُتلِف نفسَه.
        Cache::put($key, $newCount, now()->addMinutes(self::LOCKOUT_MEMORY_MINUTES));

        if ($newCount >= self::MAX_FAILED_ATTEMPTS_WINDOW) {
            $step = (int) Cache::get($stepKey, 0);
            $minutes = self::LOCKOUT_LADDER_MINUTES[
                min($step, count(self::LOCKOUT_LADDER_MINUTES) - 1)
            ];

            Cache::put($lockKey, now()->addMinutes($minutes),
                now()->addMinutes($minutes));
            Cache::put($stepKey, $step + 1, now()->addMinutes(self::LOCKOUT_MEMORY_MINUTES));

            // والعدّادُ يُصفَّر ليبدأ الشوطُ التالي — والدرجةُ تبقى.
            Cache::put($key, 0, now()->addMinutes(self::LOCKOUT_MEMORY_MINUTES));
        }

        // AMIAL-SEC-LOGIN-001: عند بلوغ الحدّ بالضبط → حدث قفل (سجلّ تدقيق + إشعار
        // أمني) مرّة واحدة، لا في كلّ محاولة لاحقة محظورة.
        if ($newCount === self::MAX_FAILED_ATTEMPTS_WINDOW) {
            $this->onAccountLocked($role, $identifier, $request, $userId);
        }

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

    private function recordSuccess(string $role, int $userId, Request $request, ?string $identifier = null): void
    {
        // AMIAL-FIX: العدّاد مُخزَّن بمفتاح المعرّف (هاتف/رقم) لا بمعرّف المستخدم،
        // فكان لا يُصفّر عند نجاح الدخول. نُصفّر المفتاحين معاً الآن.
        Cache::forget("auth_attempts:{$role}:" . md5((string)$userId));
        if ($identifier !== null && $identifier !== '') {
            Cache::forget("auth_attempts:{$role}:" . md5($identifier));
        }

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

    /**
     * AMIAL-SEC-LOGIN-001: يُستدعى عند بلوغ حدّ المحاولات الفاشلة. يُسجّل حدثاً في
     * سجلّ التدقيق (سلسلة تجزئة غير قابلة للعبث) ويُرسل إشعاراً أمنياً للمستخدم إن
     * أمكن التعرّف عليه. كلا الجانبين «أفضل جهد» — لا يكسران مسار الدخول أبداً.
     */
    private function onAccountLocked(string $role, string $identifier, Request $request, ?int $userId): void
    {
        try {
            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $userId,
                'subject_type' => 'user',
                'subject_id' => $userId,
                'action' => 'AUTH_LOCKOUT',
                'decision_code' => 'ACCOUNT_TEMP_LOCKED',
                'reason' => 'قفل مؤقّت بعد ' . self::MAX_FAILED_ATTEMPTS_WINDOW . ' محاولات دخول فاشلة',
                'severity' => 'warning',
                'context' => [
                    'role' => $role,
                    'identifier_masked' => $this->maskIdentifier($identifier),
                    'ip' => (string) $request->ip(),
                    'lockout_minutes' => self::FAILED_ATTEMPTS_LOCKOUT_MINUTES,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('lockout audit failed', ['err' => $e->getMessage()]);
        }

        if ($userId) {
            try {
                $user = User::find($userId);
                if ($user) {
                    app(\App\Services\NotificationService::class)->dispatch(
                        $user,
                        'security_alert',
                        'تنبيه أمني: محاولات دخول فاشلة',
                        'رصدنا ' . self::MAX_FAILED_ATTEMPTS_WINDOW . ' محاولات دخول فاشلة لحسابك، وتمّ قفله مؤقّتاً لمدة '
                            . self::FAILED_ATTEMPTS_LOCKOUT_MINUTES . ' دقيقة. إن لم تكن أنت، غيّر كلمة المرور فوراً.',
                        data: [
                            'ip' => (string) $request->ip(),
                            'at' => now()->toIso8601String(),
                        ],
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('lockout notify failed', ['err' => $e->getMessage()]);
            }
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
