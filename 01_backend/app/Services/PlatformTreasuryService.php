<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AMIAL-TREASURY-ISSUANCE-001
 *
 * The only service allowed to create platform float. A balance increment is
 * not an accounting event by itself: the matching cash reserve and the audit
 * evidence are both part of the event.
 */
class PlatformTreasuryService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array{transaction_id:?string,entry:LedgerJournalEntry,duplicate:bool} */
    /**
     * AMIAL-TREASURY-SOURCE-001 — **«أين وصل المالُ الحقيقيّ؟»**
     *
     * ══════════════════════════════════════════════════════════════════
     * المحورُ ٣٣ من وثيقة المركز الماليّ يطلب أن يختفي «إنشاء رصيد»
     * ويحلَّ محلَّه **طلبُ إصدارٍ يبدأ من مصدر التمويل**، ولكلّ مصدرٍ
     * مسارُه.
     *
     * **ولماذا هذا ليس حقلاً إضافيّاً في نموذج:** كلُّ إصدارٍ كان يُقيَّد
     * مديناً على `TREASURY_CASH_RESERVE` — أي **«دخل نقدٌ إلى خزينتنا»**.
     * وهي جملةٌ صحيحةٌ إن جاء المالُ توريدَ خزينةٍ أو تسويةَ وكيل، **وكاذبةٌ
     * في ثلاثةٍ من الخمسة**:
     *
     * | المصدر | الحقيقةُ المحاسبيّة | ما كان يُكتب |
     * |---|---|---|
     * | إيداعٌ بنكيّ | زاد **رصيدُ البنك** لا نقدُ الخزينة | نقدٌ في الخزينة |
     * | رأسُ مالٍ من المالك | زادت **حقوقُ الملكيّة** | نقدٌ في الخزينة |
     * | تصحيحٌ محاسبيّ | حسابٌ **معلَّقٌ** ينتظر تفسيراً | نقدٌ في الخزينة |
     *
     * فميزانيّةٌ تُقرأ من هذا الدفتر تقول إنّ في الخزنة نقداً ليس فيها.
     * **ومن جرَد الخزنةَ وجدها ناقصةً بمقدار كلّ إيداعٍ بنكيٍّ منذ النشأة**
     * — ولا عطلَ في أيّ سجلّ، ولا سببَ يُعرف.
     *
     * @var array<string,array{label:string,account:string,type:string,normal:string,name:string}>
     */
    public const FUNDING_SOURCES = [
        'treasury_supply' => [
            'label' => 'توريدُ نقدٍ إلى الخزينة',
            'account' => 'TREASURY_CASH_RESERVE', 'type' => 'asset', 'normal' => 'debit',
            'name' => 'احتياطي نقد الخزينة الموثق',
        ],
        'agent_settlement' => [
            'label' => 'تسويةُ وكيل — سلّم نقداً',
            'account' => 'TREASURY_CASH_RESERVE', 'type' => 'asset', 'normal' => 'debit',
            'name' => 'احتياطي نقد الخزينة الموثق',
        ],
        'bank_deposit' => [
            'label' => 'إيداعٌ في حسابٍ بنكيّ',
            'account' => 'TREASURY_BANK', 'type' => 'asset', 'normal' => 'debit',
            'name' => 'أرصدة المنصّة البنكيّة',
        ],
        'capital' => [
            'label' => 'رأسُ مالٍ أو تمويلٌ داخليّ',
            'account' => 'PLATFORM_CAPITAL', 'type' => 'equity', 'normal' => 'credit',
            'name' => 'رأس مال المنصّة',
        ],
        'accounting_correction' => [
            'label' => 'تصحيحٌ محاسبيّ',
            'account' => 'ISSUANCE_CORRECTION_SUSPENSE', 'type' => 'asset', 'normal' => 'debit',
            'name' => 'حساب معلّق لتصحيحات الإصدار قيد التحقيق',
        ],
    ];

    /**
     * AMIAL-TREASURY-MAKERCHECKER-001 — **«لا زرَّ واحدٌ يخلق مليارات».**
     *
     * ══════════════════════════════════════════════════════════════════
     * المحورُ ٣٤ من وثيقة المركز الماليّ يطلب مساراً لا ضغطة:
     *
     *     Maker → يُدخل إثباتَ التمويل → Pending Verification
     *           → Checker يتحقّق → معاينةٌ محاسبيّة → اعتماد
     *           → ترحيلٌ ذرّيّ → مصالحة → اكتمال
     *
     * **وهذا الإجراءُ وحدَه يخلق مالاً من العدم.** سواه يُحرّك موجوداً أو
     * يفتح باباً؛ وهذا يزيد المعروضَ من الريال الإلكترونيّ. وموظّفٌ واحدٌ
     * يملك زرَّه يملك المنصّةَ كلَّها.
     *
     * ══════════════════════════════════════════════════════════════════
     * **وحاجزٌ يجعل الميزةَ مستحيلةً ليس حماية** — وهو عطلٌ وقع في هذا
     * المشروع من قبل (‏أمرُ ترحيل المحافظ اشترط قضيّةً لا يمكن أن توجد
     * لحظةَ النشر). فمنصّةٌ بمشرفٍ واحدٍ لا يمكن أن يُعتمَد فيها طلب:
     * **يُقال ذلك صراحةً بالاسم**، ولا يُترك الطلبُ معلّقاً إلى الأبد
     * ينتظر من لا وجودَ له.
     *
     * **والحدُّ يُقرأ من الإعداد لا يُثبَّت:** صفرٌ يعني «كلُّ إصدارٍ
     * يحتاج عينين ثانيتين» وهو الافتراضُ الآمن، ومن أراد استثناءً
     * تشغيليّاً ضبطه صراحةً — **فيكون قراراً موقَّعاً لا سهواً**.
     *
     * @return array{mode:'issued'|'pending_approval', entry?:mixed,
     *   transaction_id?:?string, duplicate?:bool, request?:\App\Models\ApprovalRequest}
     */
    public function requestIssuance(
        string|int|float $amount,
        User $actor,
        string $reference,
        string $reason,
        string $fundingSource = 'treasury_supply',
        ?string $idempotencyKey = null,
    ): array {
        $amount = MoneyService::normalize($amount);
        $threshold = MoneyService::normalize(
            (string) config('amial.treasury.approval_threshold', '0'));

        // دون الحدّ: يُصدَر مباشرةً — وهو استثناءٌ مضبوطٌ بقرارٍ لا سهو.
        if (bccomp($threshold, '0', 4) > 0 && bccomp($amount, $threshold, 4) <= 0) {
            return $this->issueAdminFloat(
                $amount, $actor, $reference, $reason, $idempotencyKey, $fundingSource
            ) + ['mode' => 'issued'];
        }

        // **ولا يُقبل طلبٌ لا يستطيع أحدٌ اعتمادَه.**
        if (! $this->hasAnotherApprover($actor)) {
            throw new RuntimeException(
                'لا يمكن إصدارُ رصيدٍ الآن: **لا مشرفَ ثانٍ يعتمد الطلب**. '
                . 'وإصدارُ المال يحتاج عينين ثانيتين. '
                . 'أنشئ حسابَ مشرفٍ آخرَ، أو اضبط '
                . '`AMIAL_TREASURY_APPROVAL_THRESHOLD` بقرارٍ مكتوبٍ لاستثناء '
                . 'المبالغ الصغيرة.'
            );
        }

        // **ولا يُصدَر شيءٌ هنا** — الترحيلُ يقع لحظةَ الاعتماد بيد
        // المُراجِع. وإصدارٌ يقع عند الطلب ثمّ «يُعتمَد» توقيعٌ على أمرٍ واقع.
        $request = app(ApprovalService::class)->submit(
            maker: $actor,
            actionType: 'treasury_issuance',
            subjectUserId: (int) Helpers::get_admin_id(),
            reason: trim($reason),
            payload: [
                'amount' => $amount,
                'reference' => trim($reference),
                'funding_source' => $fundingSource,
                // **المعاينةُ المحاسبيّةُ تُحفَظ مع الطلب** — فالمُراجِعُ
                // يرى القيدَ الذي سيقع قبل أن يعتمد، لا بعده.
                'preview' => $this->issuancePreview($amount, $fundingSource),
            ],
        );

        return ['mode' => 'pending_approval', 'request' => $request];
    }

    /**
     * **المعاينةُ المحاسبيّةُ قبل الاعتماد** (المحور ٣٦ من وثيقة الدفتر).
     *
     * مُراجِعٌ يوقّع على «إصدار ٥٠٠٬٠٠٠» لا يعرف أين ستقع. **ورقمٌ بلا
     * قيدٍ يُوقَّع عليه بالثقة لا بالمراجعة.**
     *
     * @return array<string,mixed>
     */
    private function issuancePreview(string $amount, string $fundingSource): array
    {
        $src = self::FUNDING_SOURCES[$fundingSource] ?? self::FUNDING_SOURCES['treasury_supply'];

        return [
            'funding_source' => $fundingSource,
            'funding_source_label' => $src['label'],
            'lines' => [
                ['account' => $src['account'], 'name' => $src['name'],
                    'direction' => 'مدين', 'amount' => $amount],
                ['account' => 'USER_WALLET_'.Helpers::get_admin_id(), 'name' => 'محفظة الإدارة',
                    'direction' => 'دائن', 'amount' => $amount],
            ],
            'effect' => sprintf(
                'يزيد «%s» بمقدار %s، ويزيد الرصيدُ الإلكترونيُّ المُصدَر بالمقدار نفسِه.',
                $src['name'], $amount),
        ];
    }

    /** أثمّة مشرفٌ آخرُ يستطيع الاعتماد؟ */
    private function hasAnotherApprover(User $actor): bool
    {
        return User::where('type', ADMIN_TYPE)
            ->where('id', '!=', $actor->id)
            ->where('is_active', 1)
            ->exists();
    }

    public function issueAdminFloat(
        string|int|float $amount,
        ?User $actor,
        string $reference,
        string $reason,
        ?string $idempotencyKey = null,
        string $fundingSource = 'treasury_supply',
    ): array {
        $amount = MoneyService::normalize($amount);
        if (! MoneyService::isPositive($amount)) {
            throw new RuntimeException('مبلغ الإصدار يجب أن يكون أكبر من صفر');
        }

        $reference = trim($reference);
        $reason = trim($reason);
        if ($reference === '' || $reason === '') {
            throw new RuntimeException('مرجع الإثبات وسبب الإصدار إلزاميان');
        }

        // **ولا مصدرَ مخترَع.** قيمةٌ غيرُ معروفةٍ تعني أنّ الطرفَ المقابلَ
        // مجهول، والقيدُ عندئذٍ يخترع حساباً — فيُرفض قبل أن يُكتب حرف.
        if (! isset(self::FUNDING_SOURCES[$fundingSource])) {
            throw new RuntimeException(
                'مصدرُ التمويل غيرُ معروف: «'.$fundingSource.'». '
                . 'المتاح: '.implode('، ', array_keys(self::FUNDING_SOURCES))
            );
        }

        $source = self::FUNDING_SOURCES[$fundingSource];

        // A retry or double click must return the original event, never mint
        // again.  The ledger column is 80 chars while external references and
        // HTTP idempotency keys may be longer, so store a fixed-size digest.
        $key = 'treasury:' . hash('sha256', $reference);

        return DB::transaction(function () use ($amount, $actor, $reference, $reason, $key, $idempotencyKey, $fundingSource, $source) {
            $existing = LedgerJournalEntry::where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return [
                    'transaction_id' => $existing->metadata['legacy_transaction_id'] ?? null,
                    'entry' => $existing,
                    'duplicate' => true,
                ];
            }

            $adminId = Helpers::get_admin_id();
            $operationalWallet = EMoney::where('user_id', $adminId)->lockForUpdate()->first();
            if (! $operationalWallet) {
                throw new RuntimeException('محفظة الإدارة غير موجودة');
            }

            // A unique ledger row cannot be locked before it exists.  The
            // operational wallet is the shared serialisation point for every
            // issuance, so re-check after acquiring that lock to close the
            // "both requests saw no row" race.
            $existing = LedgerJournalEntry::where('idempotency_key', $key)->first();
            if ($existing) {
                return [
                    'transaction_id' => $existing->metadata['legacy_transaction_id'] ?? null,
                    'entry' => $existing,
                    'duplicate' => true,
                ];
            }

            $adminWallet = $this->ledger->getOrCreateUserWallet($adminId);
            $ledgerBefore = $this->ledger->computeBalanceFromLines($adminWallet->id);
            if (bccomp((string) $operationalWallet->current_balance, $ledgerBefore, 4) !== 0) {
                throw new RuntimeException(
                    'لا يمكن إصدار رصيد فوق انحراف قائم: طابق محفظة الإدارة مع الدفتر أولاً'
                );
            }

            $legacyTransactionId = Helpers::make_transaction([
                'from_user_id' => $adminId,
                'to_user_id' => $adminId,
                'user_id' => $adminId,
                'type' => 'credit',
                'transaction_type' => CASH_IN,
                'ref_trans_id' => null,
                'amount' => $amount,
                'note' => "إصدار خزينة: {$reference} — {$reason}",
            ]);

            if (! $legacyTransactionId) {
                throw new RuntimeException('تعذّر تسجيل حركة المحفظة');
            }

            // **الطرفُ المقابلُ يتبع مصدرَ المال لا يُثبَّت على النقد.**
            // (‏انظر جدولَ الكذبات فوق `FUNDING_SOURCES`.)
            $counter = $this->ledger->getOrCreateSystemAccount(
                $source['account'], $source['type'], $source['name'], $source['normal'],
            );

            $entry = $this->ledger->post(
                sourceType: 'treasury_issuance',
                sourceId: $legacyTransactionId,
                description: "إصدار رصيد خزينة: {$reference}",
                // **ومحفظةُ الإدارة دائنةٌ دائماً** — الرصيدُ المُصدَر
                // التزامٌ على المنصّة تجاه حامله. أمّا الطرفُ الآخر فيتبع
                // المصدر: أصلٌ يُدان (‏نقدٌ أو بنك)، وحقوقُ ملكيّةٍ تُدان
                // أيضاً في هذا الاتّجاه لأنّ المالكَ ضخّ فزادت مطلوبيّةُ
                // المنصّة تجاهه — والقيدُ متوازنٌ في الحالين.
                lines: [
                    ['account' => $counter->account_code, 'direction' => 'debit', 'amount' => $amount,
                        'description' => $source['label']],
                    ['account' => $adminWallet->account_code, 'direction' => 'credit', 'amount' => $amount,
                        'description' => 'رصيد الإدارة المُصدر'],
                ],
                idempotencyKey: $key,
                createdByUserId: $actor?->id,
                metadata: [
                    'reference' => $reference,
                    'reason' => $reason,
                    'funding_source' => $fundingSource,
                    'funding_source_label' => $source['label'],
                    'counter_account' => $source['account'],
                    'legacy_transaction_id' => $legacyTransactionId,
                    'actor_user_id' => $actor?->id,
                    'issued_at' => now()->toIso8601String(),
                    // The business reference is the ledger identity.  Keep a
                    // non-sensitive fingerprint of a transport retry key for
                    // incident correlation without letting it mint a second
                    // issuance for the same proof reference.
                    'request_idempotency_hash' => $idempotencyKey
                        ? hash('sha256', $idempotencyKey) : null,
                ],
            );

            $this->audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $actor?->id,
                'subject_type' => 'treasury_issuance', 'subject_id' => $entry->entry_ulid,
                'action' => 'TREASURY_FLOAT_ISSUED', 'decision_code' => 'POSTED',
                'reason' => $reason, 'transaction_id' => $legacyTransactionId,
                'severity' => 'critical',
                'context' => [
                    'amount' => $amount, 'reference' => $reference,
                    'ledger_entry_ulid' => $entry->entry_ulid, 'idempotency_key' => $key,
                ],
            ]);

            return ['transaction_id' => $legacyTransactionId, 'entry' => $entry, 'duplicate' => false];
        });
    }
}
