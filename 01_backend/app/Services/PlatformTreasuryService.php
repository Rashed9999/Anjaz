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
