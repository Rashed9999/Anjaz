<?php

namespace App\Services;

use App\Models\Aml\AmlFlaggedTransaction;
use App\Models\Aml\AmlInvestigation;
use App\Models\Aml\AmlUserRiskProfile;
use App\Models\AmialNotification;
use App\Models\EMoney;
use App\Models\KycDocument;
use App\Models\PaymentRequest;
use App\Models\RegistrationDossier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CUSTOMER-CENTER-001 — مركز العملاء (الفصل ٠٢).
 *
 * **ما تشترطه الوثيقة حرفياً:** «يجب أن يستطيع موظف خدمة العملاء إدارة
 * العميل **بالكامل من شاشة واحدة** دون الحاجة للانتقال بين أكثر من لوحة».
 *
 * وكان العميل موزَّعاً على ثلاث شاشات: «مركز العملاء» (قائمة وإنشاء)،
 * و«مركز الدعم» (ملفّ ٣٦٠)، و«كشف المعاملات». فموظّفٌ على الهاتف مع عميل
 * يقفز بينها ويفقد سياقه في كلّ قفزة.
 *
 * ══════════════════════════════════════════════════════════════
 * **ولماذا كلّ تبويبٍ نقطةُ نهايةٍ مستقلّة:**
 *
 * الوثيقة تشترط فتح الملفّ في **≤ ثانية واحدة**. وتجميعُ التبويبات العشرة
 * في ردٍّ واحد يجعل زمن الفتح مجموعَ أبطئها — وأبطؤها «العمليات» و«التدقيق»
 * وهما ما يُفتح أقلّ.
 *
 * فتُحمَّل «النظرة العامة» وحدها عند الفتح، ويُحمَّل كلّ تبويبٍ عند الضغط
 * عليه. والنتيجة أنّ الشرط يتحقّق لا بتحسينِ استعلامٍ بل بعدم تنفيذه أصلاً
 * حتى يُطلب.
 * ══════════════════════════════════════════════════════════════
 */
class CustomerCenterService
{
    public function __construct(
        private readonly CustomerStatusResolver $status,
        private readonly PiiAccessAuditService $pii,
        private readonly LedgerReportService $ledgerReports,
        private readonly KycDocumentService $kycDocuments,
    ) {
    }

    /**
     * كلّ فتحٍ لتبويبٍ يُسجَّل — والوثيقة تشترط ذلك صراحةً:
     * «يسجل النظام تلقائياً: جميع عمليات البحث، فتح صفحة العميل، تنفيذ أي
     * إجراء».
     *
     * ويُسجَّل **لكلّ تبويب** لا لفتح الصفحة وحده: من يفتح «المخاطر» أو
     * «الهوية» يطّلع على بياناتٍ أشدّ حساسيةً ممّن يقرأ الرصيد، وتسجيلٌ
     * واحد عند الدخول يُسوّي بينهما.
     */
    private function logAccess(int $actorId, int $customerId, string $tab, ?string $reason = null): void
    {
        $this->pii->logAccess(
            actorUserId: $actorId,
            subjectType: 'user',
            subjectId: $customerId,
            fieldName: 'customer_center:' . $tab,
            accessType: 'view',
            accessReason: $reason ?: 'فتح تبويب ' . $tab . ' في مركز العملاء',
        );
    }

    /** صلاحية كشف مستقلة: فتح الملف لا يعني تلقائياً كشف كل PII فيه. */
    private function canRevealPii(int $actorId): bool
    {
        return User::find($actorId)?->hasPlatformPermission('platform.customers.pii.reveal') ?? false;
    }

    private function maskPhone(?string $phone): string
    {
        $value = trim((string) $phone);
        if ($value === '') return '—';
        return mb_substr($value, 0, 3) . '••••' . mb_substr($value, -2);
    }

    private function maskEmail(?string $email): string
    {
        $value = trim((string) $email);
        if ($value === '' || !str_contains($value, '@')) return $value ?: '—';
        [$local, $domain] = explode('@', $value, 2);
        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    private function maskIp(?string $ip): string
    {
        $value = trim((string) $ip);
        if ($value === '' || $value === '—') return '—';
        if (str_contains($value, ':')) return '••••:••••';
        $parts = explode('.', $value);
        return count($parts) === 4 ? $parts[0] . '.' . $parts[1] . '.••.••' : '••••';
    }

    // ── التبويب ١: نظرة عامة ────────────────────────────────────────────

    public function overview(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'overview');

        $wallet = EMoney::where('user_id', $customer->id)->first();
        $financialTruth = $this->ledgerReports->walletTruth((int) $customer->id);
        $status = $this->status->resolve($customer);
        $risk = AmlUserRiskProfile::find($customer->id);

        $revealPii = $this->canRevealPii($actorId);
        return [
            'profile' => [
                'id' => (int) $customer->id,
                'name' => trim((string) ($customer->f_name . ' ' . $customer->l_name)) ?: '—',
                'phone' => $revealPii ? (string) $customer->phone : $this->maskPhone($customer->phone),
                'email' => $revealPii ? (string) ($customer->email ?? '—') : $this->maskEmail($customer->email),
                'type' => $this->typeLabel((int) $customer->type),
                'zone_code' => (string) ($customer->zone_code ?? '—'),
                'registered_at' => $customer->created_at?->toIso8601String(),
                'last_active_at' => $customer->last_active_at?->toIso8601String()
                    ?? $customer->updated_at?->toIso8601String(),
            ],
            'status' => $status,
            'wallet' => [
                // E-Money هو الرصيد التشغيلي. لا يُعرض كـ«متحقَّق» قبل أن
                // تُقارن به سطور الدفتر في financial_truth أدناه.
                'current_balance' => (string) ($financialTruth['operational_balance'] ?? $wallet?->current_balance ?? '0'),
                'held_balance' => (string) ($wallet->held_balance ?? '0'),
                'pending_balance' => (string) ($wallet->pending_balance ?? '0'),
                // المتاح = الحاليّ ناقص المحجوز. ويُحسب هنا لا يُترك للموظّف:
                // من يقرأ «الرصيد ١٠٠٠» ولا يرى «محجوز ٩٠٠» يعِد العميل بما
                // لا يستطيع.
                'available_balance' => bcsub(
                    (string) ($wallet->current_balance ?? '0'),
                    (string) ($wallet->held_balance ?? '0'),
                    4,
                ),
            ],
            'kyc' => array_merge($this->kycReconciliation($customer), [
                // NULL ليس «clear»؛ لم تُجرَ الشاشة فلا يجوز أن تطمئن الموظف.
                'sanction_status' => $this->sanctionStatus($customer),
            ]),
            'risk' => $this->riskSummary($risk),
            'limits' => $this->limits($customer),
            'financial_truth' => $financialTruth,
            'notes' => $this->notes($customer),
            'counters' => [
                'open_tickets' => Schema::hasTable('support_tickets')
                    ? DB::table('support_tickets')->where('user_id', $customer->id)
                        ->whereIn('status', ['open', 'investigating', 'waiting_customer'])->count()
                    : 0,
                'open_investigations' => Schema::hasTable('aml_investigations')
                    ? AmlInvestigation::where('subject_user_id', $customer->id)->open()->count()
                    : 0,
                'pending_kyc_docs' => KycDocument::where('user_id', $customer->id)
                    ->where('status', KycDocument::STATUS_PENDING)->count(),
                'blocked_devices' => DB::table('user_log_histories')
                    ->where('user_id', $customer->id)->where('is_blocked', 1)->count(),
            ],
        ];
    }

    private function typeLabel(int $type): string
    {
        return match ($type) {
            ADMIN_TYPE => 'موظّف منصّة',
            AGENT_TYPE => 'وكيل',
            MERCHANT_TYPE => 'تاجر',
            default => 'عميل',
        };
    }

    private function isKycVerified(User $customer): bool
    {
        return (int) ($customer->is_kyc_verified ?? 0) === 1;
    }

    private function accountKycState(User $customer): string
    {
        return match ((int) ($customer->is_kyc_verified ?? 0)) {
            1 => 'verified',
            2 => 'rejected',
            3 => 'not_submitted',
            default => 'pending',
        };
    }

    /**
     * حالة قرار الحساب وحالة سجلّ المستندات حقيقتان مختلفتان.
     *
     * لا يجعل غيابُ مستندات KYC الحديثة حساباً قديماً غير موثّق من تلقاء
     * نفسه؛ ذلك قرارٌ يغيّر قدرة العميل على المال. لكن إخفاء الغياب خلف
     * شارة «موثّق» يضلّل الموظف ويمنع تنظيف بيانات الترحيل. لذلك نعرض
     * مصدرَي الحقيقة مع نتيجة مصالحة صريحة، من دون أي تعديل على الحساب.
     *
     * @return array<string,mixed>
     */
    private function kycReconciliation(User $customer): array
    {
        $tier = max(0, min(3, (int) ($customer->kyc_tier ?? 0)));
        // الفئة 0/1 لا تتطلب ملفّات في KycDocumentService. عند غيابها نعرض
        // جاهزية ترقية الفئة 2، لا ندّعي أن الحساب الحالي ناقص المستندات.
        $documentTier = $tier >= 2 ? $tier : 2;
        $completeness = $this->kycDocuments->completenessFor($customer, $documentTier);
        $documents = KycDocument::query()->where('user_id', $customer->id)
            ->get(['id', 'status']);
        $hasDocuments = $documents->isNotEmpty();
        $hasPending = $documents->contains('status', KycDocument::STATUS_PENDING);
        $hasRejected = $documents->contains('status', KycDocument::STATUS_REJECTED);
        $accountState = $this->accountKycState($customer);
        $updateRequired = Schema::hasColumn('users', 'kyc_update_required')
            && (int) ($customer->kyc_update_required ?? 0) === 1;

        [$state, $severity, $label, $description] = match (true) {
            $accountState === 'rejected' => [
                'account_rejected', 'danger', 'قرار الحساب: مرفوض',
                'رُفض توثيق الحساب؛ راجع سبب الرفض وسجلّ المستندات قبل أي إعادة تقديم.',
            ],
            $updateRequired => [
                'update_required', 'danger', 'تحديث الهوية مطلوب',
                'العمليات الحساسة مقيّدة حتى تُرفع الوثائق وتُعتمد بقرار منفصل.',
            ],
            $accountState === 'verified' && $completeness['complete'] => [
                'verified_with_current_documents', 'success', 'موثّق والمستندات مكتملة',
                'قرار الحساب وسجلّ مستندات الفئة الحالية متوافقان.',
            ],
            $accountState === 'verified' && !$hasDocuments => [
                'verified_without_document_record', 'warning', 'موثّق في الحساب — لا سجل مستندات',
                'قرار الحساب نافذ، لكن لا توجد مستندات حديثة في السجل؛ هذه فجوة ترحيل/أرشفة وليست دليلاً على إلغاء التوثيق.',
            ],
            $accountState === 'verified' => [
                'verified_documents_incomplete', 'warning', 'موثّق في الحساب — سجل المستندات غير مكتمل',
                'قرار الحساب نافذ، لكن ملفّ مستندات الفئة المستهدفة يحتاج مراجعة أو استكمالاً.',
            ],
            $completeness['complete'] => [
                'documents_complete_pending_account_decision', 'warning', 'المستندات مكتملة — قرار الحساب معلّق',
                'اكتمال المستندات لا يساوي اعتماد الحساب؛ يلزم قرار مستقل من مراجع مخوّل.',
            ],
            $hasPending => [
                'documents_under_review', 'warning', 'مستندات قيد المراجعة',
                'الحساب غير موثّق بعد؛ توجد مستندات تنتظر مراجعة أو استكمالاً.',
            ],
            $hasRejected => [
                'documents_require_resubmission', 'danger', 'مستندات تحتاج إعادة تقديم',
                'الحساب غير موثّق؛ يوجد مستند مرفوض ولا توجد حزمة مكتملة معتمدة.',
            ],
            $accountState === 'not_submitted' => [
                'not_submitted', 'secondary', 'لم تُقدَّم هوية',
                'لا يوجد قرار اعتماد ولا ملف مستندات للفئة المستهدفة.',
            ],
            default => [
                'account_pending', 'warning', 'قرار الحساب معلّق',
                'الحساب غير موثّق ولم تكتمل مستندات الفئة المستهدفة.',
            ],
        };

        return [
            'is_verified' => $this->isKycVerified($customer),
            'account_state' => $accountState,
            'tier' => $tier,
            'document_target_tier' => $documentTier,
            'update_required' => $updateRequired,
            'update_requested_at' => $updateRequired
                ? $customer->kyc_update_requested_at?->toIso8601String() : null,
            'completeness' => $completeness,
            'reconciliation' => [
                'state' => $state,
                'severity' => $severity,
                'label' => $label,
                'description' => $description,
            ],
        ];
    }

    private function sanctionStatus(User $customer): string
    {
        // القيمة الافتراضية clear في users لا تثبت أن فحصاً وقع. لا يتحول
        // "لم نفحص" إلى براءة لمجرد default في هجرة قديمة.
        if (!Schema::hasColumn('users', 'sanction_status')
            || !Schema::hasColumn('users', 'sanction_checked')
            || !(bool) $customer->sanction_checked) {
            return 'not_screened';
        }

        if (Schema::hasTable('sanction_screening_logs')) {
            $latest = DB::table('sanction_screening_logs')->where('user_id', $customer->id)
                ->orderByDesc('screened_at')->value('result');
            if ($latest === 'confirmed_match') return 'blocked';
            if ($latest === 'potential_match') return 'flagged';
            if ($latest === 'clear') return 'clear';
        }

        return in_array($customer->sanction_status, ['clear', 'flagged', 'blocked'], true)
            ? (string) $customer->sanction_status : 'not_screened';
    }

    private function transactionLabel(?string $type): string
    {
        return match ((string) $type) {
            'send_money' => 'تحويل صادر', 'received_money' => 'تحويل وارد',
            'cash_in' => 'إيداع نقدي', 'cash_out' => 'سحب نقدي',
            'payment_request' => 'طلب أموال', 'safe_payment' => 'دفع آمن',
            default => 'عملية مالية',
        };
    }

    private function securityEventLabel(?string $type): string
    {
        return match ((string) $type) {
            'PHONE_CHANGED' => 'تم تغيير رقم الهاتف',
            'PIN_CHANGED' => 'تم تغيير الرمز السرّي',
            'PIN_FAILED' => 'محاولة رمز سرّي فاشلة',
            'LOGIN_FAILED' => 'محاولة دخول فاشلة',
            'LOGIN_SUCCESS' => 'تسجيل دخول ناجح',
            'PASSWORD_CHANGED' => 'تم تغيير كلمة المرور',
            default => 'حدث أمني',
        };
    }

    private function notificationTypeLabel(?string $type): string
    {
        return match ((string) $type) {
            'transaction' => 'عملية مالية', 'payment_request' => 'طلب أموال',
            'kyc' => 'الهوية والتحقق', 'security' => 'أمان الحساب',
            'support' => 'الدعم', default => 'إشعار',
        };
    }

    /**
     * الصفر نتيجة فحص، لا بديل عن غياب الفحص. وجود Profile فارغ أو قديم لا
     * يكفي لتقديم «0» على أنّه درجة مخاطر محسوبة.
     */
    private function riskSummary(?AmlUserRiskProfile $profile): array
    {
        $assessedAt = $profile?->last_evaluation_at;
        $measured = $profile !== null && $assessedAt !== null;

        return [
            'state' => $measured ? 'measured' : 'unassessed',
            'score' => $measured ? (string) $profile->current_risk_score : null,
            'level' => $measured ? (string) ($profile->risk_level ?: 'unknown') : 'unassessed',
            'assessed_at' => $assessedAt?->toIso8601String(),
            'source' => $measured ? 'aml_risk_profile' : null,
            // قد تكون القائمة السوداء اليدوية موجودة قبل دورة التقييم؛ لا
            // نخفيها لمجرد أن الدرجة لم تُحسب.
            'override' => (string) ($profile->manual_override ?? 'none'),
            'override_reason' => $profile?->override_reason,
        ];
    }

    /** الحدّ النافذ: استثناء العميل إن وُجد، وإلّا حدّ فئته. */
    private function limits(User $customer): array
    {
        $override = is_array($customer->limit_override)
            ? $customer->limit_override
            : (json_decode((string) $customer->limit_override, true) ?: []);

        $tier = Schema::hasTable('kyc_tier_limits')
            ? DB::table('kyc_tier_limits')->where('tier', (int) ($customer->kyc_tier ?? 0))->first()
            : null;

        $pick = fn (string $key) => $override[$key] ?? ($tier ? $tier->{$key} : null);

        return [
            'source' => $override !== [] ? 'استثناء خاصّ بالعميل' : 'حدّ الفئة',
            // الصفر رقم صالح في بعض السياسات؛ أمّا null فهو «لا توجد سياسة
            // صالحة معرّفة» ولا يجوز للواجهة أن تخلطهما.
            'max_balance' => $pick('max_balance') === null ? null : (string) $pick('max_balance'),
            'max_single_transaction' => $pick('max_single_transaction') === null ? null : (string) $pick('max_single_transaction'),
            'max_daily_total' => $pick('max_daily_total') === null ? null : (string) $pick('max_daily_total'),
            'max_monthly_total' => $pick('max_monthly_total') === null ? null : (string) $pick('max_monthly_total'),
            'has_override' => $override !== [],
            'state' => $tier || $override !== [] ? 'configured' : 'not_configured',
        ];
    }

    public function notes(User $customer): array
    {
        if (!Schema::hasTable('customer_notes')) {
            return [];
        }

        return DB::table('customer_notes as n')
            ->leftJoin('users as u', 'u.id', '=', 'n.author_id')
            ->where('n.user_id', $customer->id)
            // المثبّتة أوّلاً: «اتّصل ثلاث مرّات بخصوص المشكلة نفسها» يجب أن
            // تُرى قبل أن يبدأ الموظّف من الصفر.
            ->orderByDesc('n.is_pinned')->orderByDesc('n.created_at')
            ->limit(50)
            ->selectRaw('n.id, n.body, n.is_pinned, n.created_at, u.f_name, u.l_name')
            ->get()
            ->map(fn ($n) => [
                'id' => (int) $n->id,
                'body' => (string) $n->body,
                'is_pinned' => (bool) $n->is_pinned,
                'author' => trim((string) ($n->f_name . ' ' . $n->l_name)) ?: '—',
                'created_at' => (string) $n->created_at,
            ])->all();
    }

    // ── التبويب ٢: المحافظ ──────────────────────────────────────────────

    public function wallets(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'wallets');

        $main = EMoney::where('user_id', $customer->id)->first();

        $rows = [[
            'name' => 'المحفظة الرئيسية',
            'balance' => (string) ($main->current_balance ?? '0'),
            'reserved' => (string) ($main->held_balance ?? '0'),
            'pending' => (string) ($main->pending_balance ?? '0'),
            'currency' => 'YER',
            'status' => (int) ($customer->is_temp_blocked ?? 0) === 1 ? 'مجمَّدة' : 'نشطة',
        ]];

        // صناديق العائلة: محافظ فعلية للعميل وإن كانت جماعية. وإخفاؤها يجعل
        // مجموع ما يملكه العميل مجهولاً للموظّف.
        if (Schema::hasTable('family_funds')) {
            DB::table('family_funds as f')
                ->join('family_fund_members as m', 'm.fund_id', '=', 'f.id')
                ->where('m.user_id', $customer->id)
                ->select('f.name', 'f.balance', 'f.id')
                ->get()
                ->each(function ($f) use (&$rows) {
                    $rows[] = [
                        'name' => 'صندوق عائلة: ' . ($f->name ?? '#' . $f->id),
                        'balance' => (string) ($f->balance ?? '0'),
                        'reserved' => '0', 'pending' => '0',
                        'currency' => 'YER', 'status' => 'مشترك',
                    ];
                });
        }

        return ['wallets' => $rows];
    }

    // ── التبويب ٣: العمليات ─────────────────────────────────────────────

    public function transactions(User $customer, int $actorId, array $filters = []): array
    {
        $this->logAccess($actorId, $customer->id, 'transactions');

        if (!Schema::hasTable('transactions')) {
            return ['items' => []];
        }

        // الطرفان معاً: `from_user_id` و`to_user_id` إلى جانب `user_id`.
        // وقصرُها على `user_id` يُخفي عن الموظّف العملياتِ التي كان العميل
        // **مستقبِلها** — وهي نصف قصّته.
        $q = DB::table('transactions')
            ->where(fn ($w) => $w->where('user_id', $customer->id)
                ->orWhere('from_user_id', $customer->id)
                ->orWhere('to_user_id', $customer->id));

        if (!empty($filters['type'])) {
            $q->where('transaction_type', $filters['type']);
        }
        if (!empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->where('created_at', '<=', $filters['to'] . ' 23:59:59');
        }

        $items = $q->orderByDesc('id')->limit(300)->get()
            ->map(fn ($t) => [
                'transaction_id' => (string) ($t->transaction_id ?? $t->id),
                'type' => $this->transactionLabel($t->transaction_type ?? null),
                'technical_type' => (string) ($t->transaction_type ?? ''),
                'debit' => (string) ($t->debit ?? '0'),
                'credit' => (string) ($t->credit ?? '0'),
                'balance' => (string) ($t->balance ?? '0'),
                'created_at' => (string) $t->created_at,
            ])->all();

        return [
            'items' => $items,
            'types' => DB::table('transactions')->distinct()
                ->orderBy('transaction_type')->pluck('transaction_type')->filter()->values()->all(),
        ];
    }

    // ── التبويب ٤: الأجهزة ──────────────────────────────────────────────

    public function devices(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'devices');
        $revealPii = $this->canRevealPii($actorId);

        return [
            'devices' => DB::table('user_log_histories')
                ->where('user_id', $customer->id)
                ->orderByDesc('is_active')->orderByDesc('updated_at')
                ->limit(50)->get()
                ->map(fn ($d) => [
                    'id' => (int) $d->id,
                    'device_id' => $revealPii ? (string) $d->device_id : '••••' . mb_substr((string) $d->device_id, -6),
                    'device_model' => (string) ($d->device_model ?: '—'),
                    'os' => (string) ($d->os ?: '—'),
                    'app_version' => $d->app_version ?? null,
                    'ip_address' => $revealPii ? (string) ($d->ip_address ?: '—') : $this->maskIp($d->ip_address),
                    'is_active' => (bool) $d->is_active,
                    'is_trusted' => (bool) ($d->is_trusted ?? false),
                    'is_blocked' => (bool) ($d->is_blocked ?? false),
                    'block_reason' => $d->block_reason ?? null,
                    'last_seen_at' => $d->last_seen_at ?? $d->updated_at,
                ])->all(),
        ];
    }

    // ── التبويب ٥: المصادقة ─────────────────────────────────────────────

    public function authentication(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'authentication');

        $events = Schema::hasTable('account_security_events')
            ? DB::table('account_security_events')
                ->where('user_id', $customer->id)
                ->orderByDesc('id')->limit(100)->get()
            : collect();

        return [
            'pin' => [
                'is_set' => !empty($customer->pin_code ?? $customer->pin ?? null),
                'failed_attempts' => (int) ($customer->pin_failed_attempts ?? 0),
                'locked_until' => $customer->pin_locked_until ?? null,
            ],
            // يُفرَّق بين أنواع الأحداث لا تُعرض كتلةً واحدة: تغييرُ الهاتف
            // أخطر ما يقع على حساب — وهو مدفون بين مئة محاولة دخول لو خُلطا.
            'phone_changes' => $events->filter(fn ($e) => str_contains((string) $e->event_type, 'PHONE'))
                ->map(fn ($e) => $this->securityEvent($e))->values()->all(),
            'pin_changes' => $events->filter(fn ($e) => str_contains((string) $e->event_type, 'PIN')
                && !str_contains((string) $e->event_type, 'FAILED'))
                ->map(fn ($e) => $this->securityEvent($e))->values()->all(),
            'failed_attempts' => $events->filter(fn ($e) => str_contains((string) $e->event_type, 'FAILED'))
                ->map(fn ($e) => $this->securityEvent($e))->values()->all(),
            'other' => $events->filter(fn ($e) => !str_contains((string) $e->event_type, 'PHONE')
                && !str_contains((string) $e->event_type, 'PIN'))
                ->map(fn ($e) => $this->securityEvent($e))->take(30)->values()->all(),
        ];
    }

    private function securityEvent($e): array
    {
        return [
            'type' => $this->securityEventLabel($e->event_type),
            'technical_type' => (string) $e->event_type,
            'severity' => (string) ($e->severity ?? 'info'),
            'ip' => (string) ($e->ip_address ?? '—'),
            'note' => (string) ($e->note ?? ''),
            'at' => (string) $e->created_at,
        ];
    }

    // ── التبويب ٦: الهوية ───────────────────────────────────────────────

    public function kyc(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'kyc');

        return array_merge($this->kycReconciliation($customer), [
            'documents' => KycDocument::with('reviewer:id,f_name,l_name')
                ->where('user_id', $customer->id)
                ->orderByDesc('created_at')->get()
                ->map(fn (KycDocument $d) => [
                    'id' => (int) $d->id,
                    'doc_type' => $d->doc_type,
                    'doc_label' => KycDocument::TYPE_LABELS[$d->doc_type] ?? $d->doc_type,
                    'status' => $d->status,
                    'rejection_reason' => $d->rejection_reason,
                    'ocr_status' => $d->ocr_status ?? 'not_run',
                    'expires_at' => $d->document_expires_at?->format('Y-m-d'),
                    'reviewer' => $d->reviewer
                        ? trim((string) ($d->reviewer->f_name . ' ' . $d->reviewer->l_name)) : null,
                    'reviewed_at' => $d->reviewed_at?->toIso8601String(),
                    'uploaded_at' => $d->created_at?->toIso8601String(),
                ])->all(),
            // لا نكشف payload هنا؛ التبويب يثبت وجود ملفه ويقود إلى شاشة
            // الأرشيف المحروسة التي تسجّل فتح البيانات الحساسة.
            'registration_dossiers' => RegistrationDossier::query()
                ->where('subject_user_id', $customer->id)->latest()->limit(10)->get()
                ->map(fn (RegistrationDossier $d) => [
                    'reference' => $d->reference, 'source' => $d->source,
                    'state' => $d->state, 'has_paper_form' => (bool) $d->paper_form_encrypted_path,
                    'created_at' => $d->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    // ── التبويب ٧: المخاطر ──────────────────────────────────────────────

    public function risk(User $customer, int $actorId): array
    {
        // فتحُ ملفّ المخاطر اطّلاعٌ أشدّ حساسيةً — يُسجَّل بسببٍ مميَّز.
        $this->logAccess($actorId, $customer->id, 'risk', 'مراجعة ملفّ مخاطر العميل');

        $profile = AmlUserRiskProfile::find($customer->id);

        return [
            'profile' => $this->riskSummary($profile),
            'sanction_status' => $this->sanctionStatus($customer),
            'flagged' => Schema::hasTable('aml_flagged_transactions')
                ? AmlFlaggedTransaction::where('actor_user_id', $customer->id)
                    ->orderByDesc('id')->limit(30)->get()
                    ->map(fn ($f) => [
                        'flag_ulid' => $f->flag_ulid,
                        'amount' => (string) $f->amount,
                        'risk_score' => (string) $f->total_risk_score,
                        'status' => $f->current_status,
                        'at' => $f->created_at?->toIso8601String(),
                    ])->all()
                : [],
            'investigations' => Schema::hasTable('aml_investigations')
                ? AmlInvestigation::where('subject_user_id', $customer->id)
                    ->orderByDesc('id')->limit(20)->get()
                    ->map(fn (AmlInvestigation $i) => [
                        'id' => (int) $i->id,
                        'case_number' => $i->case_number,
                        'status' => $i->status,
                        'priority' => $i->priority,
                        'decision' => $i->decision,
                        'age_hours' => $i->ageHours(),
                    ])->all()
                : [],
            'evaluations' => Schema::hasTable('aml_rule_evaluations')
                ? DB::table('aml_rule_evaluations')
                    ->where('actor_user_id', $customer->id)->where('matched', true)
                    ->orderByDesc('id')->limit(30)
                    ->get(['rule_code', 'amount', 'contributed_risk_score', 'created_at'])
                    ->map(fn ($e) => (array) $e)->all()
                : [],
        ];
    }

    // ── التبويب ٨: الدعم ────────────────────────────────────────────────

    public function support(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'support');
        $revealPii = $this->canRevealPii($actorId);

        $tickets = Schema::hasTable('support_tickets')
            ? DB::table('support_tickets')
                ->where('user_id', $customer->id)
                ->orderByDesc('id')->limit(50)->get()
                ->map(fn ($t) => [
                    'ticket_number' => (string) ($t->ticket_number ?? $t->id),
                    'subject' => (string) ($t->subject ?? '—'),
                    'status' => (string) ($t->status ?? '—'),
                    'priority' => (string) ($t->priority ?? '—'),
                    'created_at' => (string) $t->created_at,
                    'updated_at' => (string) $t->updated_at,
                ])->all()
            : [];

        $requests = Schema::hasTable('payment_requests')
            ? PaymentRequest::with([
                'requester:id,f_name,l_name,phone',
                'recipient:id,f_name,l_name,phone',
            ])->where(function ($q) use ($customer): void {
                $q->where('requester_user_id', $customer->id)
                    ->orWhere('recipient_user_id', $customer->id);
            })->orderByDesc('id')->limit(100)->get()
                // `$revealPii` يُقرأ داخل الإغلاق — وبلا `use` هنا ترتفع
                // `Undefined variable` فتردّ الشاشةُ ٥٠٠ **بعد** أن تمرّ من
                // كلّ حارس صلاحيّة. والأسوأ أنّ الافتراض الصامت في PHP هو
                // `null` أي «لا تكشف»، فلو لم تُرفَع لكان الحجبُ يقع بالصدفة
                // لا بالقرار — وحجبٌ بالصدفة يُرفَع بالصدفة.
                ->map(function (PaymentRequest $request) use ($customer, $revealPii): array {
                    $outgoing = (int) $request->requester_user_id === (int) $customer->id;
                    $other = $outgoing ? $request->recipient : $request->requester;
                    $fallback = $outgoing ? $request->recipient_name : null;
                    $name = trim(($other?->f_name ?? '') . ' ' . ($other?->l_name ?? ''));

                    return [
                        'request_ulid' => (string) $request->request_ulid,
                        'short_code' => (string) $request->short_code,
                        'direction' => $outgoing ? 'outgoing' : 'incoming',
                        'counterparty' => $name !== '' ? $name : (string) ($fallback ?: '—'),
                        'counterparty_phone' => $revealPii ? (string) ($other?->phone
                            ?? ($outgoing ? $request->recipient_phone : $request->requester?->phone)
                            ?? '—') : $this->maskPhone((string) ($other?->phone
                                ?? ($outgoing ? $request->recipient_phone : $request->requester?->phone)
                                ?? '')),
                        'amount' => (string) $request->amount,
                        'status' => (string) $request->status,
                        'share_method' => (string) $request->share_method,
                        'paid_transaction_id' => $request->paid_transaction_id,
                        'note' => $request->note,
                        'created_at' => $request->created_at?->toIso8601String(),
                        'paid_at' => $request->paid_at?->toIso8601String(),
                    ];
                })->all()
            : [];

        return [
            'tickets' => $tickets,
            'payment_requests' => $requests,
        ];
    }

    // ── التبويب ٩: الإشعارات ────────────────────────────────────────────

    public function notifications(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'notifications');

        return [
            'items' => AmialNotification::where('user_id', $customer->id)
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn ($n) => [
                    'type' => $this->notificationTypeLabel($n->type),
                    'technical_type' => (string) $n->type,
                    'title' => (string) $n->title,
                    'body' => (string) $n->body,
                    // «أُرسل» و«قُرئ» ليسا واحداً: شكوى «لم يصلني إشعار» تُحسم
                    // بالفرق بينهما — أُرسل ولم يُقرأ مشكلةُ العميل، ولم يُرسل
                    // مشكلتنا.
                    'sent_at' => $n->created_at?->toIso8601String(),
                    'read_at' => $n->read_at?->toIso8601String(),
                    'is_read' => $n->read_at !== null,
                ])->all(),
        ];
    }

    // ── التبويب ١٠: التدقيق ─────────────────────────────────────────────

    public function audit(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'audit');

        $rows = [];

        // قرارات الموظّفين على هذا العميل.
        if (Schema::hasTable('audit_decisions')) {
            DB::table('audit_decisions as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
                ->where(function ($q) use ($customer) {
                    $q->where('a.subject_id', (string) $customer->id)
                      ->orWhere('a.actor_user_id', $customer->id);
                })
                ->orderByDesc('a.id')->limit(100)
                ->selectRaw('a.action, a.decision_code, a.severity, a.reason, a.created_at,
                    a.actor_type, u.f_name, u.l_name')
                ->get()->each(function ($a) use (&$rows) {
                    $rows[] = [
                        'source' => 'قرار',
                        'action' => (string) $a->action,
                        'code' => (string) ($a->decision_code ?? ''),
                        'severity' => (string) ($a->severity ?? 'info'),
                        'note' => (string) ($a->reason ?? ''),
                        'actor' => trim((string) ($a->f_name . ' ' . $a->l_name)) ?: (string) $a->actor_type,
                        'at' => (string) $a->created_at,
                    ];
                });
        }

        // أحداث أمان الحساب.
        if (Schema::hasTable('account_security_events')) {
            DB::table('account_security_events')
                ->where('user_id', $customer->id)
                ->orderByDesc('id')->limit(100)->get()
                ->each(function ($e) use (&$rows) {
                    $rows[] = [
                        'source' => 'أمان',
                        'action' => (string) $e->event_type,
                        'code' => '',
                        'severity' => (string) ($e->severity ?? 'info'),
                        'note' => (string) ($e->note ?? ''),
                        'actor' => '—',
                        'at' => (string) $e->created_at,
                    ];
                });
        }

        // من اطّلع على بيانات هذا العميل — والوثيقة تشترط تسجيله.
        // ويُعرَض للمراجع نفسه: «من فتح ملفّي؟» سؤالٌ رقابيّ لا فضول.
        if (Schema::hasTable('pii_access_logs')) {
            DB::table('pii_access_logs as p')
                ->leftJoin('users as u', 'u.id', '=', 'p.actor_user_id')
                ->where('p.subject_id', $customer->id)
                ->orderByDesc('p.id')->limit(50)
                ->selectRaw('p.field_name, p.access_reason, p.created_at, u.f_name, u.l_name')
                ->get()->each(function ($p) use (&$rows) {
                    $rows[] = [
                        'source' => 'اطّلاع',
                        'action' => (string) $p->field_name,
                        'code' => '',
                        'severity' => 'info',
                        'note' => (string) ($p->access_reason ?? ''),
                        'actor' => trim((string) ($p->f_name . ' ' . $p->l_name)) ?: '—',
                        'at' => (string) $p->created_at,
                    ];
                });
        }

        // مصادر ثلاثة في خطٍّ زمنيٍّ واحد: القصّة لا تُقرأ إن وُزّعت على
        // ثلاثة جداول يقلّبها الموظّف بيده.
        usort($rows, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return ['items' => array_slice($rows, 0, 150)];
    }
}
