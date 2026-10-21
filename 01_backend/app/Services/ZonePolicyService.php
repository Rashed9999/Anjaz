<?php

namespace App\Services;

use App\Models\User;
use App\Support\YemenGovernorates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AMIAL-ZONE-001
 *
 * ZonePolicyService — الحارس المركزي لتغطية الخدمات المالية وسياسة المحافظات.
 *
 * المبادئ (من الوثيقة):
 *   - أي حساب بلا موقع إقامة أو نشاط موثق: لا عمليات مالية.
 *   - التحويلات الداخلية متاحة بعد التحقق، لأنها لا تعبر نقداً.
 *   - الخدمات النقدية تحددها سياسة المحافظات الفعالة المراجَعة.
 *   - IP/VPN: إشارات risk فقط، **ليست** الحُكم النهائي.
 *     الحُكم النهائي مبني على user.zone_code (يُعيَّن عند الـ KYC).
 *
 * يستخدم من middleware EnforceZonePolicy وأيضاً من ZonePolicyController.
 */
class ZonePolicyService
{
    /** المنطقة الوحيدة المسموح لها بعمليات مالية في v1 */
    public const ALLOWED_OPERATIONAL_ZONE = 'SOUTH';

    /** كل القيم الصحيحة لـ zone_code (للـ validation) */
    public const VALID_ZONES = ['SOUTH', 'NORTH', 'MIDDLE', 'OTHER', 'UNKNOWN'];

    /**
     * AMIAL-ZONE-LABEL-001: الاسم العربي لرمز المنطقة — مصدر موحّد لكل الأماكن
     * (كان تعيين SOUTH ناقصاً في EnforcesFinancialPolicy فيظهر «SOUTH» حرفياً).
     */
    public static function zoneNameAr(?string $zone): string
    {
        return match ($zone) {
            'SOUTH' => 'الجنوب',
            'NORTH' => 'الشمال',
            'MIDDLE' => 'الوسط',
            'OTHER' => 'أخرى',
            default => 'غير محددة',
        };
    }

    /**
     * المصدر الحاكم للتغطية النقدية هو آخر سياسة محافظات فعّالة.
     * zone_code يبقى للتوافق والتدقيق، ولا يقرر التغطية للحسابات الجديدة.
     *
     * @return list<string>
     */
    public function operationalGovernorates(): array
    {
        try {
            if (Schema::hasTable('operational_governorate_policies')) {
                $policy = DB::table('operational_governorate_policies')
                    ->where('is_active', true)->orderByDesc('version')
                    ->first(['governorate_codes']);
                if ($policy !== null) {
                    $codes = json_decode((string) $policy->governorate_codes, true);
                    if (is_array($codes)) {
                        return array_values(array_unique(array_filter(
                            $codes,
                            static fn ($code) => is_string($code) && YemenGovernorates::isValidCode($code),
                        )));
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return array_values(array_unique(array_filter(
            (array) config('amial.operational_governorates', []),
            static fn ($code) => is_string($code) && YemenGovernorates::isValidCode($code),
        )));
    }

    /** @return array{identity_ready:bool,cash_available:bool,governorate:?string,source:string} */
    public function coverage(User $user): array
    {
        $governorate = YemenGovernorates::codeFromName(
            (string) ($user->verified_residence_governorate ?? '')
        );
        if ($governorate !== null && !empty($user->residence_verified_at)) {
            return [
                'identity_ready' => true,
                'cash_available' => in_array($governorate, $this->operationalGovernorates(), true),
                'governorate' => $governorate,
                'source' => 'verified_residence',
            ];
        }

        // توافق آمن للحسابات القديمة المعتمدة قبل موثّق. التسجيل الحديث يبدأ
        // UNKNOWN فلا يستطيع الاستفادة من هذا المسار.
        $legacyGovernorate = YemenGovernorates::codeFromName(
            (string) ($user->residence_governorate ?? '')
        );
        if ($legacyGovernorate !== null && (int) ($user->is_kyc_verified ?? 0) === 1) {
            return [
                'identity_ready' => true,
                'cash_available' => in_array($legacyGovernorate, $this->operationalGovernorates(), true),
                'governorate' => $legacyGovernorate,
                'source' => 'legacy_verified_residence',
            ];
        }

        $zone = (string) ($user->zone_code ?? 'UNKNOWN');
        return [
            'identity_ready' => $zone !== '' && $zone !== 'UNKNOWN',
            'cash_available' => $zone === self::ALLOWED_OPERATIONAL_ZONE,
            'governorate' => null,
            'source' => 'legacy_zone',
        ];
    }

    /**
     * AMIAL-ZONE-BOUNDARY-001 — عمليات لا يعبر فيها نقد.
     *
     * القيمة تنتقل من محفظة إلى محفظة داخل دفتر واحد مقوَّم بريال واحد.
     * لا بنك مركزي يُلمَس ولا عملة تُصرَف، فموقع المستخدم لا يغيّر شيئاً.
     *
     * كانت هذه العمليات محكومة بـ SOUTH كغيرها، فمن انتقل شمالاً — أو سافر
     * أسبوعاً — يفقد محفظته كاملة وهو لم يفعل ما يضرّ. العقوبة على السفر
     * لا على المخاطرة.
     *
     * تبقى مشروطة بأن يكون للحساب منطقة مُسندة (لا UNKNOWN): ذلك شرط هوية
     * موثّقة لا شرط جغرافيا.
     */
    public const LEDGER_ACTIONS = [
        'send_money',
        'request_money',
        'refund',
        'split_bill',
        'donate',
        'safe_payment_create',
        'safe_payment_fund',
        'safe_payment_release',
        'family_fund_contribute',
        'family_fund_disburse',
    ];

    /**
     * AMIAL-ZONE-BOUNDARY-001 — عمليات يعبر فيها النقد حدَّ النظام.
     *
     * هنا وحدها يقع خطر العملتين: الوكيل يسلّم أو يستلم ورقاً حقيقياً،
     * والتاجر يسعّر بضاعته بعملة منطقته. عملية واحدة عبر الخط تعني صرفاً
     * بسعر خاطئ — والدفاتر تبقى متوازنة بينما الخسارة حقيقية.
     *
     * الطرف الحاسم هنا هو **الوكيل أو التاجر** لا العميل: هو العقدة
     * المعتمدة المعروفة الموقع، وهو الذي يلمس النقد.
     */
    public const CASH_BOUNDARY_ACTIONS = [
        'cash_in',
        'cash_out',
        'add_money',
        'withdraw',
        'withdraw_request',
        'withdraw_execute',
        'merchant_payment',
        'qr_payment',
        'pay_bill',
    ];

    /**
     * كل العمليات المالية — اتحاد المجموعتين.
     *
     * تبقى للتوافق: مواضع كثيرة تسأل «هل هذه عملية مالية؟» بلا تمييز.
     */
    public const FINANCIAL_ACTIONS = [
        'send_money',
        'request_money',
        'cash_in',
        'cash_out',
        'add_money',
        'withdraw',
        'pay_bill',
        'safe_payment_fund',
        'safe_payment_release',
        // AMIAL-FIX: أسماء إجراءات مستعملة في الراوتات كانت مفقودة فتُرفض بـ
        // «Action not in known list» (الدفع الآمن/التبرع/الاسترجاع).
        'safe_payment_create',
        'donate',
        'refund',
        'withdraw_request',
        'withdraw_execute',
        'merchant_payment',
        'qr_payment',
        'split_bill',
        'family_fund_contribute',
        'family_fund_disburse',
    ];

    /**
     * العمليات الـ read-only المسموحة دائماً (حتى خارج SOUTH).
     */
    public const READ_ACTIONS = [
        'view_balance',
        'view_history',
        'view_profile',
        'view_terms',
        'view_notifications',
    ];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * هل يُسمح للمستخدم بتنفيذ هذه العملية؟
     *
     * @return array{allowed: bool, decision_code: string, reason: string, account_zone: string, request_zone: ?string}
     */
    public function authorize(?User $user, string $action, ?Request $request = null): array
    {
        // مستخدم غير مصادق: نرفض العمليات المالية فقط
        if (!$user) {
            $isFinancial = in_array($action, self::FINANCIAL_ACTIONS, true);
            return [
                'allowed' => !$isFinancial,
                'decision_code' => $isFinancial ? 'AUTH_REQUIRED' : 'ALLOWED_PUBLIC',
                'reason' => $isFinancial ? 'Authentication required' : 'Public read action',
                'account_zone' => 'UNKNOWN',
                'request_zone' => null,
            ];
        }

        $accountZone = $user->zone_code ?? 'UNKNOWN';
        $requestZone = $this->detectRequestZone($request);

        // القراءة متاحة دائماً؛ لا ينبغي أن يحجب طلب موثّق غير المكتمل
        // الوصول إلى الحساب أو السجل.
        if (in_array($action, self::READ_ACTIONS, true)) {
            return [
                'allowed' => true,
                'decision_code' => 'ALLOWED_READ',
                'reason' => 'Read-only action',
                'account_zone' => $accountZone,
                'request_zone' => $requestZone,
            ];
        }

        $coverage = $this->coverage($user);

        // غياب محل إقامة/نشاط موثق يمنع المال، لا فتح طلب موثّق أو مراجعته.
        if (!$coverage['identity_ready']) {
            return $this->deny(
                $user,
                $action,
                'ACCOUNT_LOCATION_UNVERIFIED',
                'Verified residence or operating location is required before financial operations',
                $accountZone,
                $requestZone,
                $coverage,
            );
        }

        // 2) read actions مسموحة دائماً للمصادقين
        if (in_array($action, self::READ_ACTIONS, true)) {
            return [
                'allowed' => true,
                'decision_code' => 'ALLOWED_READ',
                'reason' => 'Read-only action',
                'account_zone' => $accountZone,
                'request_zone' => $requestZone,
            ];
        }

        // 3) AMIAL-ZONE-BOUNDARY-001 — عمليات الدفتر: لا نقد يعبر، فلا معنى
        // لحجبها جغرافياً. القيمة تنتقل بين محفظتين في دفتر واحد مقوَّم بريال
        // واحد. حجبها كان يعاقب المسافر والمنتقل بلا مقابل في الحماية.
        if (in_array($action, self::LEDGER_ACTIONS, true)) {
            return [
                'allowed' => true,
                'decision_code' => 'ALLOWED_LEDGER_ONLY',
                'reason' => 'Ledger-internal action — no cash crosses the currency line',
                'account_zone' => $accountZone,
                'request_zone' => $requestZone,
            ];
        }

        // عمليات النقد تقرأ نسخة المحافظات الفعالة، لا SOUTH ثابتة.
        if (in_array($action, self::CASH_BOUNDARY_ACTIONS, true)) {
            if (!$coverage['cash_available']) {
                return $this->deny(
                    $user,
                    $action,
                    'TX_ZONE_BLOCKED',
                    'Cash-boundary services are not enabled for the verified operating governorate',
                    $accountZone,
                    $requestZone,
                    $coverage,
                );
            }

            return [
                'allowed' => true,
                'decision_code' => 'ALLOWED_CASH_COVERAGE',
                'reason' => 'Cash service enabled by operational governorate policy',
                'account_zone' => $accountZone,
                'request_zone' => $requestZone,
            ];
        }

        // 4) action غير معرف: نرفض بحذر (whitelist-only)
        return $this->deny(
            $user,
            $action,
            'ACTION_UNKNOWN',
            "Action '{$action}' not in known list",
            $accountZone,
            $requestZone,
        );
    }

    /**
     * تستخدمه الخدمات الداخلية حتى لا يعيد كل منها كتابة شرط SOUTH خاص به.
     */
    public function assertAllowed(User $user, string $action): void
    {
        $decision = $this->authorize($user, $action);
        if (!$decision['allowed']) {
            throw new RuntimeException($decision['reason'].' ['.$decision['decision_code'].']');
        }
    }

    /**
     * يحدد zone الطلب من إشارات الـ IP / Locale.
     * فقط إشارة — لا تُستخدم كحُكم نهائي (الوثيقة قسم 4).
     */
    public function detectRequestZone(?Request $request): ?string
    {
        if (!$request) {
            return null;
        }

        // طبقات الإشارة:
        // 1) header صريح من التطبيق (لو الـ Flutter يرسلها) — موثوق نسبياً
        if ($zone = $request->header('X-Amial-Zone')) {
            return in_array($zone, self::VALID_ZONES, true) ? $zone : 'OTHER';
        }

        // 2) GeoIP من IP — نتركها لـ provider منفصل (Stevebauman/Location موجود في providers)
        // في v0.7 نكتفي بـ null؛ في v0.8 نضيف geo lookup حقيقي.
        return null;
    }

    /**
     * يبني response payload لـ GET /api/v1/amial/policy/session
     */
    public function buildSessionPolicy(?User $user, Request $request): array
    {
        $accountZone = $user?->zone_code ?? 'UNKNOWN';
        $requestZone = $this->detectRequestZone($request);
        $coverage = $user ? $this->coverage($user) : [
            'identity_ready' => false,
            'cash_available' => false,
            'governorate' => null,
            'source' => 'anonymous',
        ];
        $ledgerActions = $coverage['identity_ready'] ? self::LEDGER_ACTIONS : [];
        $cashActions = $coverage['cash_available'] ? self::CASH_BOUNDARY_ACTIONS : [];

        return [
            'account_zone' => $accountZone,
            'request_zone' => $requestZone,
            'allowed_operational_zone' => self::ALLOWED_OPERATIONAL_ZONE,
            'operational_governorates' => $this->operationalGovernorates(),
            'verified_residence_governorate' => $coverage['governorate'],
            'coverage_source' => $coverage['source'],
            'cash_services_available' => $coverage['cash_available'],
            'can_transact' => $coverage['identity_ready'],
            'read_only_mode' => !$coverage['identity_ready'] && $user !== null,
            'available_actions' => [
                'read' => self::READ_ACTIONS,
                'financial' => array_values(array_unique(array_merge($ledgerActions, $cashActions))),
                'ledger' => $ledgerActions,
                'cash_boundary' => $cashActions,
            ],
            'banner_message' => !$user || !$coverage['identity_ready']
                ? ($user ? 'أكمل توثيق محل الإقامة أو النشاط لتفعيل الخدمات المالية.' : null)
                : (!$coverage['cash_available']
                    ? 'التحويلات الداخلية متاحة؛ الخدمات النقدية غير مفعّلة في محافظة الإقامة أو النشاط الموثقة حالياً.'
                    : null),
            'policy_version' => '2.0',
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * كتابة قرار رفض + بناء الـ payload الموحد.
     */
    private function deny(
        User $user,
        string $action,
        string $code,
        string $reason,
        string $accountZone,
        ?string $requestZone,
        ?array $coverage = null,
    ): array {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'session',
            'subject_id' => (string)$user->id,
            'action' => 'ZONE_POLICY_DENIED',
            'decision_code' => $code,
            'reason' => $reason,
            'zone_code' => $accountZone,
            'severity' => 'notice',
            'context' => [
                'requested_action' => $action,
                'account_zone' => $accountZone,
                'request_zone' => $requestZone,
                'verified_governorate' => $coverage['governorate'] ?? null,
                'coverage_source' => $coverage['source'] ?? null,
            ],
        ]);

        return [
            'allowed' => false,
            'decision_code' => $code,
            'reason' => $reason,
            'account_zone' => $accountZone,
            'request_zone' => $requestZone,
        ];
    }
}
