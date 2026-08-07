<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * AMIAL-ZONE-001
 *
 * ZonePolicyService — تطبيق سياسة "SOUTH only" المذكورة في قسم 4 من الوثيقة.
 *
 * المبادئ (من الوثيقة):
 *   - أي حساب بدون zone واضح: لا عمليات مالية.
 *   - أي عملية خارج SOUTH: ترفض من API.
 *   - خارج SOUTH: login + عرض الرصيد + سجل مسموحة.
 *     لكن: تحويل، فواتير، QR، cash-in/out، دفع آمن → مرفوضة.
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

        // 1) أي حساب بدون zone محدد: ممنوع من المالية
        if ($accountZone === 'UNKNOWN' || empty($accountZone)) {
            return $this->deny(
                $user,
                $action,
                'ACCOUNT_ZONE_UNKNOWN',
                'Account has no operational zone assigned',
                $accountZone,
                $requestZone,
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

        // 4) عمليات يعبر فيها النقد: account_zone يجب أن يساوي SOUTH
        if (in_array($action, self::CASH_BOUNDARY_ACTIONS, true)) {
            if ($accountZone !== self::ALLOWED_OPERATIONAL_ZONE) {
                return $this->deny(
                    $user,
                    $action,
                    'TX_ZONE_BLOCKED',
                    "Financial operations only allowed in zone " . self::ALLOWED_OPERATIONAL_ZONE,
                    $accountZone,
                    $requestZone,
                );
            }

            return [
                'allowed' => true,
                'decision_code' => 'ALLOWED_ZONE_OK',
                'reason' => 'Account in allowed operational zone',
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
        $allowedOperationalZone = self::ALLOWED_OPERATIONAL_ZONE;

        // هل العمليات المالية مفعّلة لهذا المستخدم؟
        $canTransact = $user !== null && $accountZone === $allowedOperationalZone;

        return [
            'account_zone' => $accountZone,
            'request_zone' => $requestZone,
            'allowed_operational_zone' => $allowedOperationalZone,
            'can_transact' => $canTransact,
            'read_only_mode' => !$canTransact && $user !== null,
            // قائمة actions المتاحة (للتطبيق ليعرض/يخفي الأزرار)
            'available_actions' => [
                'read' => self::READ_ACTIONS,
                'financial' => $canTransact ? self::FINANCIAL_ACTIONS : [],
            ],
            // رسالة معروضة في الـ banner لو read-only
            'banner_message' => $canTransact
                ? null
                : ($user
                    ? "العمليات المالية متاحة في الجنوب فقط حالياً."
                    : null),
            'policy_version' => '1.0',
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
