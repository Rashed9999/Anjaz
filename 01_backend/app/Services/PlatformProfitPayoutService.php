<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Ledger\LedgerAccount;
use App\Models\PlatformProfitPayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-PLATFORM-PROFIT-PAYOUT-001 — صرفُ إيراد المنصة لا يُنشئ محفظةً.
 *
 * الإيراد المعتمد ينتقل أولاً من PLATFORM_FEE إلى التزام مستحقّ للمالك،
 * ثم لا يُغلق إلا بقيد دفع من TREASURY_CASH_RESERVE أو TREASURY_BANK.
 * وهكذا لا تختلط سيولة المنصة بـ CASH_RESERVE الخاص بتسويات الوكلاء.
 */
class PlatformProfitPayoutService
{
    private const REVENUE_ACCOUNT = 'PLATFORM_FEE';
    private const PAYABLE_ACCOUNT = 'PLATFORM_PROFIT_PAYABLE';
    private const CASH_ACCOUNT = 'TREASURY_CASH_RESERVE';
    private const BANK_ACCOUNT = 'TREASURY_BANK';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditService $audit,
        private readonly CashHandoverService $handovers,
    ) {}

    /** @return array<string,string> */
    public function summary(): array
    {
        // شاشة القراءة لا تخلق حسابات صفريّة. إنشاء الحساب عملُ ماليّ يحدث
        // فقط داخل الطلب/الاعتماد/الدفع حيث يسجل أثره في الدفتر.
        $revenue = $this->balanceOf(self::REVENUE_ACCOUNT);
        $payable = $this->balanceOf(self::PAYABLE_ACCOUNT);
        $cash = $this->balanceOf(self::CASH_ACCOUNT);
        $bank = $this->balanceOf(self::BANK_ACCOUNT);

        $reserved = (string) PlatformProfitPayout::query()
            ->where('status', PlatformProfitPayout::STATUS_PENDING)->sum('amount');
        $available = bcsub($revenue, $reserved, 4);

        return [
            'revenue_unallocated' => $revenue,
            'reserved_for_approval' => $reserved,
            'available_to_request' => bccomp($available, '0', 4) > 0 ? $available : '0.0000',
            'approved_payable' => $payable,
            'treasury_cash_available' => $cash,
            'treasury_bank_available' => $bank,
        ];
    }

    /** @param array{amount:string|int|float,payout_method:string,beneficiary_name:string,destination_reference:string,request_note:string} $data */
    public function request(User $maker, array $data, ?string $idempotencyKey = null): PlatformProfitPayout
    {
        $amount = MoneyService::normalize($data['amount']);
        if (! MoneyService::isPositive($amount)) {
            throw new RuntimeException('مبلغ الصرف يجب أن يكون موجباً');
        }
        if (! in_array($data['payout_method'], [PlatformProfitPayout::METHOD_CASH, PlatformProfitPayout::METHOD_BANK], true)) {
            throw new RuntimeException('طريقة الصرف غير معروفة');
        }
        if (! $this->hasAnotherApprover($maker)) {
            throw new RuntimeException('لا يمكن تقديم طلب صرف: لا يوجد مشرف ثانٍ لاعتماده');
        }

        $key = trim((string) $idempotencyKey);
        $key = $key === '' ? null : 'profit-payout:'.hash('sha256', $key);

        return DB::transaction(function () use ($maker, $data, $amount, $key) {
            if ($key && ($duplicate = PlatformProfitPayout::where('idempotency_key', $key)->lockForUpdate()->first())) {
                return $duplicate->fresh(['approval']);
            }

            // هذا القفل هو نقطة التسلسل بين كل طلبات صرف الإيراد؛ فلا يستطيع
            // طلبان اجتياز الرصيد نفسه في اللحظة نفسها.
            $revenue = $this->account(self::REVENUE_ACCOUNT, 'revenue', 'إيرادات رسوم المنصّة', 'credit');
            $revenue = LedgerAccount::whereKey($revenue->id)->lockForUpdate()->firstOrFail();
            $reserved = (string) PlatformProfitPayout::where('status', PlatformProfitPayout::STATUS_PENDING)->sum('amount');
            $available = bcsub((string) $revenue->current_balance, $reserved, 4);
            if (bccomp($amount, $available, 4) > 0) {
                throw new RuntimeException('المبلغ يتجاوز الإيراد المتاح بعد الطلبات المعلقة');
            }

            $payout = PlatformProfitPayout::create([
                'payout_ulid' => (string) Str::ulid(),
                'requested_by_id' => $maker->id,
                'idempotency_key' => $key,
                'amount' => $amount,
                'status' => PlatformProfitPayout::STATUS_PENDING,
                'payout_method' => $data['payout_method'],
                'beneficiary_name' => mb_substr(trim($data['beneficiary_name']), 0, 200),
                'destination_reference' => mb_substr(trim($data['destination_reference']), 0, 500),
                'request_note' => mb_substr(trim($data['request_note']), 0, 500),
            ]);

            $approval = app(ApprovalService::class)->submit(
                maker: $maker,
                actionType: 'platform_profit_payout',
                subjectUserId: (int) $maker->id,
                reason: 'طلب صرف أرباح أميال: '.$payout->payout_ulid,
                payload: [
                    'payout_ulid' => $payout->payout_ulid,
                    'amount' => $amount,
                    'method' => $payout->payout_method,
                    'beneficiary_name' => $payout->beneficiary_name,
                    'preview' => $this->approvalPreview($payout),
                ],
            );
            $payout->update(['approval_request_id' => $approval->id]);

            $this->audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $maker->id,
                'subject_type' => 'platform_profit_payout', 'subject_id' => $payout->payout_ulid,
                'action' => 'PLATFORM_PROFIT_PAYOUT_REQUESTED', 'decision_code' => 'PENDING_APPROVAL',
                'reason' => $payout->request_note, 'severity' => 'warning',
                'context' => ['amount' => $amount, 'approval_request_id' => $approval->id,
                    'method' => $payout->payout_method],
            ]);

            return $payout->fresh(['approval']);
        });
    }

    /** ينادى حصراً من ApprovalService داخل معاملة الاعتماد. */
    public function approveFromApproval(ApprovalRequest $approval, User $checker): PlatformProfitPayout
    {
        $payout = PlatformProfitPayout::where('approval_request_id', $approval->id)->lockForUpdate()->first();
        if (! $payout || $payout->status !== PlatformProfitPayout::STATUS_PENDING) {
            throw new RuntimeException('طلب صرف الأرباح غير صالح للاعتماد');
        }

        $amount = (string) $payout->amount;
        $revenue = $this->account(self::REVENUE_ACCOUNT, 'revenue', 'إيرادات رسوم المنصّة', 'credit');
        $payable = $this->account(self::PAYABLE_ACCOUNT, 'liability', 'أرباح منصّة معتمدة مستحقة للصرف', 'credit');
        $entry = $this->ledger->post(
            sourceType: 'platform_profit_payout_approval', sourceId: $payout->payout_ulid,
            description: 'اعتماد صرف أرباح منصة أميال',
            lines: [
                ['account' => $revenue->account_code, 'direction' => 'debit', 'amount' => $amount],
                ['account' => $payable->account_code, 'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: "platform-profit-approval:{$payout->payout_ulid}",
            createdByUserId: $checker->id,
            metadata: ['payout_ulid' => $payout->payout_ulid, 'approval_request_id' => $approval->id],
        );

        $payout->update([
            'status' => PlatformProfitPayout::STATUS_APPROVED,
            'approved_by_id' => $checker->id,
            'approved_at' => now(),
            'approval_ledger_entry_ulid' => $entry->entry_ulid,
        ]);
        $this->audit->record([
            'actor_type' => 'admin', 'actor_user_id' => $checker->id,
            'subject_type' => 'platform_profit_payout', 'subject_id' => $payout->payout_ulid,
            'action' => 'PLATFORM_PROFIT_PAYOUT_APPROVED', 'decision_code' => 'APPROVED',
            'severity' => 'high', 'context' => ['amount' => $amount, 'ledger_entry_ulid' => $entry->entry_ulid],
        ]);

        return $payout->fresh();
    }

    public function rejectFromApproval(ApprovalRequest $approval, User $checker, string $reason): void
    {
        $payout = PlatformProfitPayout::where('approval_request_id', $approval->id)->lockForUpdate()->first();
        if (! $payout || $payout->status !== PlatformProfitPayout::STATUS_PENDING) {
            return;
        }
        $payout->update([
            'status' => PlatformProfitPayout::STATUS_REJECTED,
            'rejected_by_id' => $checker->id, 'rejected_at' => now(),
            'rejection_reason' => mb_substr(trim($reason), 0, 500),
        ]);
    }

    public function markFailedOrExpired(int $approvalRequestId, string $status, ?User $actor = null, ?string $reason = null): void
    {
        if (! in_array($status, [PlatformProfitPayout::STATUS_FAILED, PlatformProfitPayout::STATUS_EXPIRED], true)) {
            return;
        }
        PlatformProfitPayout::where('approval_request_id', $approvalRequestId)
            ->where('status', PlatformProfitPayout::STATUS_PENDING)
            ->update([
                'status' => $status,
                'rejected_by_id' => $actor?->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason ? mb_substr($reason, 0, 500) : null,
                'updated_at' => now(),
            ]);
    }

    public function markPaid(PlatformProfitPayout $payout, User $actor, string $paymentReference, string $proofReference, ?string $location = null): PlatformProfitPayout
    {
        return DB::transaction(function () use ($payout, $actor, $paymentReference, $proofReference, $location) {
            $payout = PlatformProfitPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status !== PlatformProfitPayout::STATUS_APPROVED) {
                throw new RuntimeException('لا يُدفع إلا طلب أرباح معتمد');
            }
            $amount = (string) $payout->amount;
            $payable = $this->account(self::PAYABLE_ACCOUNT, 'liability', 'أرباح منصّة معتمدة مستحقة للصرف', 'credit');
            $source = $payout->payout_method === PlatformProfitPayout::METHOD_CASH
                ? $this->account(self::CASH_ACCOUNT, 'asset', 'احتياطي نقد الخزينة الموثق', 'debit')
                : $this->account(self::BANK_ACCOUNT, 'asset', 'أرصدة المنصّة البنكيّة', 'debit');
            $entry = $this->ledger->post(
                sourceType: 'platform_profit_payout_payment', sourceId: $payout->payout_ulid,
                description: 'دفع أرباح منصة أميال',
                lines: [
                    ['account' => $payable->account_code, 'direction' => 'debit', 'amount' => $amount],
                    ['account' => $source->account_code, 'direction' => 'credit', 'amount' => $amount],
                ],
                idempotencyKey: "platform-profit-payment:{$payout->payout_ulid}",
                createdByUserId: $actor->id,
                metadata: ['payout_ulid' => $payout->payout_ulid, 'method' => $payout->payout_method,
                    'payment_reference' => $paymentReference, 'proof_reference' => $proofReference],
            );

            $handoverUlid = null;
            if ($payout->payout_method === PlatformProfitPayout::METHOD_CASH) {
                $handover = $this->handovers->open(
                    direction: 'platform_to_owner', amount: $amount, from: null,
                    to: $payout->requester, deliveredBy: $actor,
                    meta: ['settlement_ulid' => $payout->payout_ulid, 'location' => $location,
                        'reference' => $paymentReference, 'note' => $proofReference],
                );
                $handoverUlid = $handover['handover_ulid'];
            }
            $payout->update([
                'status' => PlatformProfitPayout::STATUS_PAID,
                'paid_by_id' => $actor->id, 'paid_at' => now(),
                'payment_reference' => mb_substr(trim($paymentReference), 0, 160),
                'proof_reference' => mb_substr(trim($proofReference), 0, 500),
                'payment_ledger_entry_ulid' => $entry->entry_ulid,
                'handover_ulid' => $handoverUlid,
            ]);
            $this->audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $actor->id,
                'subject_type' => 'platform_profit_payout', 'subject_id' => $payout->payout_ulid,
                'action' => 'PLATFORM_PROFIT_PAYOUT_PAID', 'decision_code' => 'PAID', 'severity' => 'high',
                'context' => ['amount' => $amount, 'method' => $payout->payout_method,
                    'ledger_entry_ulid' => $entry->entry_ulid, 'handover_ulid' => $handoverUlid],
            ]);
            return $payout->fresh();
        });
    }

    /** @return array<string,mixed> */
    public function confirmCashHandover(PlatformProfitPayout $payout, User $recipient, ?string $note = null): array
    {
        if ($payout->payout_method !== PlatformProfitPayout::METHOD_CASH || ! $payout->handover_ulid
            || (int) $payout->requested_by_id !== (int) $recipient->id) {
            throw new RuntimeException('لا تملك تأكيد هذا التسليم');
        }
        return $this->handovers->confirm($payout->handover_ulid, $recipient, $note);
    }

    private function account(string $code, string $type, string $name, string $normal): LedgerAccount
    {
        return $this->ledger->getOrCreateSystemAccount($code, $type, $name, $normal);
    }

    private function balanceOf(string $code): string
    {
        return (string) (LedgerAccount::where('account_code', $code)->value('current_balance') ?? '0.0000');
    }

    private function hasAnotherApprover(User $maker): bool
    {
        return User::where('type', ADMIN_TYPE)->where('id', '!=', $maker->id)->where('is_active', 1)->exists();
    }

    /** @return array<string,mixed> */
    private function approvalPreview(PlatformProfitPayout $payout): array
    {
        return [
            'lines' => [
                ['account' => self::REVENUE_ACCOUNT, 'direction' => 'مدين', 'amount' => (string) $payout->amount],
                ['account' => self::PAYABLE_ACCOUNT, 'direction' => 'دائن', 'amount' => (string) $payout->amount],
            ],
            'payment_source' => $payout->payout_method === PlatformProfitPayout::METHOD_CASH ? self::CASH_ACCOUNT : self::BANK_ACCOUNT,
            'effect' => 'ينقل الاعتماد الإيراد إلى التزام مستحق؛ ولا يصرف نقداً أو بنكاً قبل خطوة دفع مستقلة مثبتة.',
        ];
    }
}
