<?php

namespace App\Services;

use App\Models\Ledger\LedgerAccount;
use App\Models\Settlement;
use App\Models\SettlementAuditLog;
use App\Models\SettlementPartner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * USE-001 — Universal Settlement Engine (نسخة مُصحَّحة — AMIAL-FIX-USE-001).
 *
 * ⚠️ تصحيح حرج عن المسودّة الأولى (لم تُسلَّم قطّ — اكتُشف الخلل قبل
 * التسليم بفضل قاعدة "لا ادّعاء نجاح بلا تحقّق فعلي"):
 *
 *   1. استيراد LedgerAccount كان من namespace جذر خاطئ بدل الـ namespace
 *      الفرعي الصحيح الذي يعيش فيه الموديل فعلياً (راجع use statement
 *      أعلى هذا الملف للقيمة الصحيحة الحالية).
 *
 *   2. LedgerService::post() يتطلّب معاملَي مصدر العملية (نوعها ومعرّفها)
 *      كمعاملين أساسيين أوّلين — لم يكونا يُمرَّران أبداً في المسودّة الأولى.
 *
 *   3. كل سطر في $lines يستخدم مفتاح الحساب كرمز نصّي (account code) ومفتاح
 *      الاتّجاه (direction)، لا معرّف رقمي ولا مفتاح "side" كما كُتب أوّلاً.
 *
 * هذه الأخطاء الثلاثة كانت ستُسقط *كل* عملية تسوية فور تنفيذها فعلياً.
 *
 * القواعد الصارمة (من المواصفات الأصلية):
 *   ❌ لا حذف أبداً
 *   ❌ لا تعديل بعد completed / rejected
 *   ❌ لا خصم مباشر للرصيد — كل شيء عبر LedgerService
 *   ✅ أي إلغاء = Reverse Entry في الـ Ledger
 *   ✅ كل حدث مُسجَّل في settlement_audit_logs
 */
class UniversalSettlementService
{
    public const REVENUE_WALLET_CODE = 'AMIAL_REVENUE_WALLET';

    private const SOURCE_TYPE = 'settlement';

    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    // ══════════════════════════════════════════════════════════════
    // 1. إنشاء طلب تسوية
    // ══════════════════════════════════════════════════════════════

    public function create(
        int    $sourceAccountId,
        int    $partnerId,
        string $amount,
        User   $actor,
        array  $options = [],
    ): Settlement {
        $this->validateAmount($amount);

        $account = LedgerAccount::findOrFail($sourceAccountId);
        $partner = SettlementPartner::where('id', $partnerId)
            ->where('is_active', true)->first();

        if (!$partner) {
            throw new \InvalidArgumentException('الشريك غير موجود أو غير نشط');
        }

        $available = (string) ($account->current_balance ?? '0');
        if (bccomp($amount, $available, 4) > 0) {
            throw new \InvalidArgumentException(
                "الرصيد غير كافٍ. متاح: {$available}، مطلوب: {$amount}"
            );
        }

        return DB::transaction(function () use (
            $sourceAccountId, $partnerId, $amount, $actor, $options, $account
        ) {
            $settlement = Settlement::create([
                'reference_number'           => $this->generateReference(),
                'source_ledger_account_id'   => $sourceAccountId,
                'destination_partner_id'     => $partnerId,
                'destination_account_detail' => $options['destination_account'] ?? null,
                'currency'                   => $options['currency'] ?? 'YER',
                'amount'                     => $amount,
                'status'                     => Settlement::STATUS_DRAFT,
                'created_by'                 => $actor->id,
                'notes'                      => $options['notes'] ?? null,
            ]);

            $this->audit($settlement, SettlementAuditLog::ACTION_CREATED, $actor, [
                'balance_before' => $account->current_balance,
            ]);

            return $settlement;
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 2. إرسال للاعتماد
    // ══════════════════════════════════════════════════════════════

    public function submit(Settlement $settlement, User $actor): Settlement
    {
        $this->assertCanTransition($settlement, Settlement::STATUS_PENDING_APPROVAL);

        return DB::transaction(function () use ($settlement, $actor) {
            $settlement = $this->lockForTransition($settlement, Settlement::STATUS_PENDING_APPROVAL);
            $settlement->status = Settlement::STATUS_PENDING_APPROVAL;
            $settlement->save();
            $this->audit($settlement, SettlementAuditLog::ACTION_SUBMITTED, $actor);
            return $settlement->fresh();
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 3. اعتماد
    // ══════════════════════════════════════════════════════════════

    public function approve(Settlement $settlement, User $actor, ?string $notes = null): Settlement
    {
        $this->assertCanTransition($settlement, Settlement::STATUS_APPROVED);
        $this->assertIsFinanceManager($actor);

        return DB::transaction(function () use ($settlement, $actor, $notes) {
            $settlement = $this->lockForTransition($settlement, Settlement::STATUS_APPROVED);

            // AMIAL-FIX(C2): فصل المهام — من أنشأ التسوية لا يعتمدها بنفسه.
            if ((int) $settlement->created_by === (int) $actor->id) {
                throw new \RuntimeException('لا يمكن اعتماد تسوية أنشأتها بنفسك (فصل المهام)');
            }

            $settlement->status      = Settlement::STATUS_APPROVED;
            $settlement->approved_by = $actor->id;
            $settlement->approved_at = now();
            if ($notes) $settlement->notes = $notes;
            $settlement->save();

            $this->audit($settlement, SettlementAuditLog::ACTION_APPROVED, $actor);
            return $settlement->fresh();
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 4. رفض
    // ══════════════════════════════════════════════════════════════

    public function reject(Settlement $settlement, User $actor, string $reason): Settlement
    {
        $this->assertCanTransition($settlement, Settlement::STATUS_REJECTED);
        $this->assertIsFinanceManager($actor);

        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('سبب الرفض مطلوب');
        }

        return DB::transaction(function () use ($settlement, $actor, $reason) {
            $settlement = $this->lockForTransition($settlement, Settlement::STATUS_REJECTED);
            $settlement->status           = Settlement::STATUS_REJECTED;
            $settlement->rejected_by      = $actor->id;
            $settlement->rejected_at      = now();
            $settlement->rejection_reason = $reason;
            $settlement->save();

            $this->audit($settlement, SettlementAuditLog::ACTION_REJECTED, $actor, ['reason' => $reason]);
            return $settlement->fresh();
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 5. بدء التنفيذ — Debit المحفظة → Credit حساب التعليق
    // ══════════════════════════════════════════════════════════════

    public function startProcessing(Settlement $settlement, User $actor): Settlement
    {
        $this->assertCanTransition($settlement, Settlement::STATUS_PROCESSING);
        $this->assertIsFinanceManager($actor); // AMIAL-FIX(H1): التنفيذ يمسّ الدفتر

        return DB::transaction(function () use ($settlement, $actor) {
            $settlement = $this->lockForTransition($settlement, Settlement::STATUS_PROCESSING);
            $account = LedgerAccount::lockForUpdate()
                ->findOrFail($settlement->source_ledger_account_id);

            $balanceBefore = (string) $account->current_balance;

            if (bccomp((string) $settlement->amount, $balanceBefore, 4) > 0) {
                throw new \RuntimeException(
                    "الرصيد غير كافٍ. متاح: {$balanceBefore}، مطلوب: {$settlement->amount}"
                );
            }

            $suspense = $this->getSettlementSuspenseAccount();

            // AMIAL-FIX-USE-001: account=account_code (نصّ)، direction لا side
            $entry = $this->ledger->post(
                sourceType:  self::SOURCE_TYPE,
                sourceId:    (string) $settlement->id,
                description: "تسوية {$settlement->reference_number} — بدء التنفيذ",
                lines: [
                    [
                        'account'     => $account->account_code,
                        'direction'   => 'debit',
                        'amount'      => (string) $settlement->amount,
                        'description' => "خصم تسوية {$settlement->reference_number}",
                    ],
                    [
                        'account'     => $suspense->account_code,
                        'direction'   => 'credit',
                        'amount'      => (string) $settlement->amount,
                        'description' => "تعليق تسوية {$settlement->reference_number}",
                    ],
                ],
                createdByUserId: $actor->id,
                metadata: [
                    'settlement_id' => $settlement->id,
                    'reference'     => $settlement->reference_number,
                ],
            );

            $balanceAfter = (string) ($account->fresh()->current_balance ?? '0');

            $settlement->status                 = Settlement::STATUS_PROCESSING;
            $settlement->debit_journal_entry_id = $entry->id;
            $settlement->balance_before         = $balanceBefore;
            $settlement->save();

            $this->audit($settlement, SettlementAuditLog::ACTION_PROCESSING, $actor, [
                'balance_before'   => $balanceBefore,
                'balance_after'    => $balanceAfter,
                'journal_entry_id' => $entry->id,
            ]);

            return $settlement->fresh();
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 6. إكمال — Debit التعليق → Credit إيراد مكتمل
    // ══════════════════════════════════════════════════════════════

    public function complete(
        Settlement $settlement,
        User       $actor,
        string     $externalReference,
        ?string    $externalBankRef = null,
        ?string    $attachmentPath  = null,
        ?string    $notes           = null,
    ): Settlement {
        $this->assertCanTransition($settlement, Settlement::STATUS_COMPLETED);
        $this->assertIsFinanceManager($actor); // AMIAL-FIX(H1): الإكمال يمسّ الدفتر

        if (empty(trim($externalReference))) {
            throw new \InvalidArgumentException('رقم الحوالة مطلوب لإكمال التسوية');
        }

        return DB::transaction(function () use (
            $settlement, $actor, $externalReference,
            $externalBankRef, $attachmentPath, $notes
        ) {
            $settlement = $this->lockForTransition($settlement, Settlement::STATUS_COMPLETED);
            $suspense  = $this->getSettlementSuspenseAccount();
            $completed = $this->getSettlementCompletedAccount();

            $entry = $this->ledger->post(
                sourceType:  self::SOURCE_TYPE,
                sourceId:    (string) $settlement->id,
                description: "إكمال تسوية {$settlement->reference_number}",
                lines: [
                    [
                        'account'     => $suspense->account_code,
                        'direction'   => 'debit',
                        'amount'      => (string) $settlement->amount,
                        'description' => "تسوية مكتملة {$settlement->reference_number}",
                    ],
                    [
                        'account'     => $completed->account_code,
                        'direction'   => 'credit',
                        'amount'      => (string) $settlement->amount,
                        'description' => "إيراد مُحوَّل خارجياً {$externalReference}",
                    ],
                ],
                createdByUserId: $actor->id,
                metadata: [
                    'settlement_id'      => $settlement->id,
                    'external_reference' => $externalReference,
                ],
            );

            $balanceAfter = bcsub(
                (string) $settlement->balance_before,
                (string) $settlement->amount, 4
            );

            $settlement->status             = Settlement::STATUS_COMPLETED;
            $settlement->completed_by       = $actor->id;
            $settlement->completed_at       = now();
            $settlement->external_reference = $externalReference;
            $settlement->external_bank_ref  = $externalBankRef;
            $settlement->attachment_path    = $attachmentPath;
            $settlement->balance_after      = $balanceAfter;
            if ($notes) $settlement->notes  = $notes;
            $settlement->save();

            $this->audit($settlement, SettlementAuditLog::ACTION_COMPLETED, $actor, [
                'external_reference' => $externalReference,
                'balance_before'      => $settlement->balance_before,
                'balance_after'       => $balanceAfter,
                'journal_entry_id'    => $entry->id,
            ]);

            return $settlement->fresh();
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 7. إلغاء — Reverse Entry (فقط لو كان processing)
    // ══════════════════════════════════════════════════════════════

    public function cancel(Settlement $settlement, User $actor, string $reason): Settlement
    {
        $this->assertCanTransition($settlement, Settlement::STATUS_CANCELLED);

        return DB::transaction(function () use ($settlement, $actor, $reason) {
            $settlement = $this->lockForTransition($settlement, Settlement::STATUS_CANCELLED);
            if ($settlement->status === Settlement::STATUS_PROCESSING
                && $settlement->debit_journal_entry_id) {

                $suspense = $this->getSettlementSuspenseAccount();
                $source   = LedgerAccount::findOrFail($settlement->source_ledger_account_id);

                $reverseEntry = $this->ledger->post(
                    sourceType:  self::SOURCE_TYPE,
                    sourceId:    (string) $settlement->id,
                    description: "عكس تسوية {$settlement->reference_number} — إلغاء",
                    lines: [
                        [
                            'account'     => $suspense->account_code,
                            'direction'   => 'debit',
                            'amount'      => (string) $settlement->amount,
                            'description' => "عكس تعليق تسوية {$settlement->reference_number}",
                        ],
                        [
                            'account'     => $source->account_code,
                            'direction'   => 'credit',
                            'amount'      => (string) $settlement->amount,
                            'description' => "إعادة مبلغ تسوية ملغاة {$settlement->reference_number}",
                        ],
                    ],
                    createdByUserId: $actor->id,
                    isReversal: true,
                    reversesEntryId: $settlement->debit_journal_entry_id,
                    metadata: [
                        'settlement_id' => $settlement->id,
                        'cancel_reason' => $reason,
                    ],
                );
                $settlement->reverse_journal_entry_id = $reverseEntry->id;
            }

            $settlement->status       = Settlement::STATUS_CANCELLED;
            $settlement->cancelled_by = $actor->id;
            $settlement->cancelled_at = now();
            $settlement->notes        = $reason;
            $settlement->save();

            $this->audit($settlement, SettlementAuditLog::ACTION_CANCELLED, $actor, ['reason' => $reason]);
            return $settlement->fresh();
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 8. Dashboard
    // ══════════════════════════════════════════════════════════════

    public function dashboard(): array
    {
        $wallet  = $this->getOrCreateRevenueWallet();
        $month30 = now()->copy()->subDays(30);

        $byStatus = Settlement::query()
            ->selectRaw('status, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'revenue_wallet' => [
                'balance'      => (float) ($wallet->current_balance ?? 0),
                'account_code' => $wallet->account_code,
            ],
            'total_revenue_30d' => (float) Settlement::query()
                ->where('status', Settlement::STATUS_COMPLETED)
                ->where('completed_at', '>=', $month30)
                ->sum('amount'),
            'counts' => [
                'pending'    => (int) ($byStatus[Settlement::STATUS_PENDING_APPROVAL]->count ?? 0),
                'approved'   => (int) ($byStatus[Settlement::STATUS_APPROVED]->count ?? 0),
                'processing' => (int) ($byStatus[Settlement::STATUS_PROCESSING]->count ?? 0),
                'completed'  => (int) ($byStatus[Settlement::STATUS_COMPLETED]->count ?? 0),
                'rejected'   => (int) ($byStatus[Settlement::STATUS_REJECTED]->count ?? 0),
                'cancelled'  => (int) ($byStatus[Settlement::STATUS_CANCELLED]->count ?? 0),
            ],
            'amounts' => [
                'pending_approval' => (float) ($byStatus[Settlement::STATUS_PENDING_APPROVAL]->total ?? 0),
                'completed'        => (float) ($byStatus[Settlement::STATUS_COMPLETED]->total ?? 0),
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // 9. محفظة الإيرادات
    // ══════════════════════════════════════════════════════════════

    public function getOrCreateRevenueWallet(): LedgerAccount
    {
        return $this->ledger->getOrCreateSystemAccount(
            code:   self::REVENUE_WALLET_CODE,
            type:   'liability',
            name:   'محفظة إيرادات أميال',
            normal: 'credit',
        );
    }

    // ══════════════════════════════════════════════════════════════
    // Private Helpers
    // ══════════════════════════════════════════════════════════════

    private function getSettlementSuspenseAccount(): LedgerAccount
    {
        return $this->ledger->getOrCreateSystemAccount(
            code: 'SETTLEMENT_SUSPENSE', type: 'liability',
            name: 'حساب تعليق التسويات', normal: 'credit',
        );
    }

    private function getSettlementCompletedAccount(): LedgerAccount
    {
        return $this->ledger->getOrCreateSystemAccount(
            code: 'SETTLEMENT_COMPLETED', type: 'equity',
            name: 'تسويات مكتملة', normal: 'credit',
        );
    }

    private function generateReference(): string
    {
        $year = now()->format('Y');
        $last = Settlement::whereYear('created_at', $year)->count();
        $seq  = str_pad($last + 1, 5, '0', STR_PAD_LEFT);
        return "SET-{$year}-{$seq}";
    }

    private function validateAmount(string $amount): void
    {
        if (!is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new \InvalidArgumentException('المبلغ يجب أن يكون موجباً');
        }
        if (bccomp($amount, '1000000000', 4) > 0) {
            throw new \InvalidArgumentException('المبلغ يتجاوز الحدّ الأقصى');
        }
    }

    /**
     * AMIAL-FIX(C3): يعيد تحميل التسوية بقفل صفّي (lockForUpdate) داخل المعاملة
     * الجارية ثم يعيد التحقّق من صحّة الانتقال على الحالة *الطازجة*. هذا يمنع سباق
     * التنفيذ المزدوج: طلبان متزامنان يقرآن نفس الحالة القديمة ويعتمدان/ينفّذان
     * التسوية مرّتين (قيدان محاسبيان). الطلب الثاني ينتظر القفل ثم يفشل هنا.
     */
    private function lockForTransition(Settlement $settlement, string $newStatus): Settlement
    {
        $locked = Settlement::whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
        $this->assertCanTransition($locked, $newStatus);
        return $locked;
    }

    private function assertCanTransition(Settlement $s, string $newStatus): void
    {
        if ($s->isImmutable()) {
            throw new \LogicException("التسوية في حالة نهائية ({$s->status}) ولا يمكن تعديلها");
        }
        if (!$s->canTransitionTo($newStatus)) {
            throw new \LogicException("لا يمكن الانتقال من [{$s->status}] إلى [{$newStatus}]");
        }
    }

    private function assertIsFinanceManager(User $actor): void
    {
        $isAdmin = (int) ($actor->type ?? -1) === 0 // AMIAL-FIX(C1): ADMIN_TYPE=0 لا 1
            || in_array($actor->role ?? null, ['admin', 'super_admin'], true);
        if (!$isAdmin) {
            throw new \RuntimeException('صلاحية المدير المالي مطلوبة');
        }
    }

    private function audit(
        Settlement $settlement, string $action, ?User $actor = null, array $meta = [],
    ): void {
        try {
            SettlementAuditLog::create([
                'settlement_id'      => $settlement->id,
                'action'             => $action,
                'actor_user_id'      => $actor?->id,
                'actor_role'         => $actor
                    ? (((int) ($actor->type ?? -1) === 0
                        || in_array($actor->role ?? null, ['admin', 'super_admin'], true))
                        ? 'admin' : 'user')
                    : 'system',
                'ip_address'         => request()->ip(),
                'user_agent'         => mb_substr(request()->userAgent() ?? '', 0, 255),
                'external_reference' => $meta['external_reference'] ?? null,
                'balance_before'     => $meta['balance_before'] ?? null,
                'balance_after'      => $meta['balance_after']  ?? null,
                'metadata'           => array_diff_key($meta, array_flip([
                    'balance_before', 'balance_after', 'external_reference',
                ])),
                'notes'              => $meta['notes'] ?? null,
                'created_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[USE] audit log failed', [
                'settlement_id' => $settlement->id, 'action' => $action,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
