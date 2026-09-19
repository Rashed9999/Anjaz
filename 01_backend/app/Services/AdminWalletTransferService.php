<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-ADMIN-WALLET-TRANSFER-001
 *
 * تحويل الرصيد الموجود في محفظة الإدارة إلى عميل. هذا ليس إصدار مال؛ لذلك
 * طرفاه محفظتان قائمتان، ويجب أن يتغير الرصيد التشغيلي والدفتر وسجل التوافق
 * في المعاملة الذرية نفسها. لا يستدعي Helpers::make_transaction لأن ذلك
 * يغيّر e_money خارج الدفتر.
 */
class AdminWalletTransferService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly FinancialGuardService $wallets,
    ) {
    }

    /**
     * @return array{transaction_id:string, ledger_entry_ulid:string, duplicate:bool}
     */
    public function transfer(
        User $sender,
        User $recipient,
        string|int|float $amount,
        string $reason = '',
        ?string $requestIdempotencyKey = null,
        ?User $actor = null,
    ): array {
        if ($sender->id === $recipient->id) {
            throw new RuntimeException('لا يمكن التحويل من المحفظة إلى نفسها');
        }

        $amount = MoneyService::normalize($amount);
        if (! MoneyService::isPositive($amount)) {
            throw new \InvalidArgumentException('مبلغ التحويل يجب أن يكون أكبر من صفر');
        }

        // يحفظ الدفتر مفتاحاً أقصر من مفتاح HTTP، ولا نحفظ مفتاح العميل نفسه.
        $ledgerKey = $requestIdempotencyKey
            ? 'hub-xfer:' . hash('sha256', $requestIdempotencyKey)
            : null;

        return DB::transaction(function () use ($sender, $recipient, $amount, $reason, $ledgerKey, $actor): array {
            if ($ledgerKey) {
                $existing = \App\Models\Ledger\LedgerJournalEntry::where('idempotency_key', $ledgerKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return [
                        'transaction_id' => (string) ($existing->metadata['legacy_transaction_id'] ?? $existing->source_id),
                        'ledger_entry_ulid' => (string) $existing->entry_ulid,
                        'duplicate' => true,
                    ];
                }
            }

            // قفل المحافظ بترتيب واحد يمنع deadlock في أي تحويل عكسي متزامن.
            $this->wallets->lockWalletsOrdered([$sender->id, $recipient->id]);

            // المحافظ القديمة تدخل الدفتر مرة واحدة بقيد افتتاحي صريح قبل أن
            // تتحرك. أما المحفظة ذات التاريخ الدفتري فتُترك كما هي، ثم يثبت
            // فحص المطابقة أدناه أنها لا تحمل انحرافاً قائماً.
            $this->openWalletBalanceIfPristine($sender->id, 'ترحيل محفظة الإدارة قبل تحويل موثّق');
            $this->openWalletBalanceIfPristine($recipient->id, 'ترحيل محفظة المستلم قبل تحويل موثّق');

            $senderWallet = $this->wallets->lockWallet($sender->id);
            $recipientWallet = $this->wallets->lockWallet($recipient->id);
            $senderLedger = $this->ledger->getOrCreateUserWallet($sender->id, (string) $senderWallet->zone_code);
            $recipientLedger = $this->ledger->getOrCreateUserWallet($recipient->id, (string) $recipientWallet->zone_code);

            $this->assertWalletMatchesLedger($senderWallet->current_balance, $senderLedger->id, 'الإدارة');
            $this->assertWalletMatchesLedger($recipientWallet->current_balance, $recipientLedger->id, 'المستلم');

            if (! MoneyService::gte((string) $senderWallet->current_balance, $amount)) {
                throw new InsufficientBalanceException(
                    userId: $sender->id,
                    required: $amount,
                    available: (string) $senderWallet->current_balance,
                );
            }

            $transfer = new Transfer();
            $transfer->sender = $sender->id;
            $transfer->receiver = $recipient->id;
            $transfer->receiver_type = (string) $recipient->type;
            $transfer->amount = $amount;
            $transfer->save();
            $transfer->unique_id = $transfer->id . random_int(100000, 999999999);
            $transfer->save();

            // سجل التوافق مع شاشات الحركات القديمة. لا يحرّك رصيداً؛ الرصيد
            // لا يغيّره إلا FinancialGuardService بعد نجاح قيد الدفتر.
            $creditId = (string) Str::ulid();
            $debitId = (string) Str::ulid();
            $balanceAfterRecipient = MoneyService::add((string) $recipientWallet->current_balance, $amount);
            $balanceAfterSender = MoneyService::sub((string) $senderWallet->current_balance, $amount);

            Transaction::create([
                'user_id' => $recipient->id,
                'transaction_id' => $creditId,
                'transaction_type' => CASH_IN,
                'debit' => '0.0000', 'credit' => $amount, 'amount' => $amount,
                'balance' => $balanceAfterRecipient,
                'from_user_id' => $sender->id, 'to_user_id' => $recipient->id,
                'note' => trim($reason) ?: 'تحويل من محفظة الإدارة',
                'idempotency_key' => $ledgerKey,
                'decision_code' => 'POSTED', 'zone_code' => (string) $recipientWallet->zone_code,
            ]);
            Transaction::create([
                'user_id' => $sender->id,
                'transaction_id' => $debitId,
                'ref_trans_id' => $creditId,
                'transaction_type' => CASH_OUT,
                'debit' => $amount, 'credit' => '0.0000', 'amount' => $amount,
                'balance' => $balanceAfterSender,
                'from_user_id' => $sender->id, 'to_user_id' => $recipient->id,
                'note' => trim($reason) ?: 'تحويل من محفظة الإدارة',
                'idempotency_key' => $ledgerKey,
                'decision_code' => 'POSTED', 'zone_code' => (string) $senderWallet->zone_code,
            ]);

            $entry = $this->ledger->post(
                sourceType: 'admin_wallet_transfer',
                sourceId: (string) $transfer->id,
                description: 'تحويل من محفظة الإدارة إلى مستخدم',
                lines: [
                    ['account' => $senderLedger->account_code, 'direction' => 'debit', 'amount' => $amount],
                    ['account' => $recipientLedger->account_code, 'direction' => 'credit', 'amount' => $amount],
                ],
                idempotencyKey: $ledgerKey,
                createdByUserId: $actor?->id,
                metadata: [
                    'legacy_transaction_id' => $creditId,
                    'transfer_id' => $transfer->id,
                    'reason' => trim($reason) ?: null,
                ],
                zoneCode: (string) $senderWallet->zone_code,
            );

            $senderAfter = $this->wallets->debit($sender->id, $amount, 'admin_wallet_transfer');
            $recipientAfter = $this->wallets->credit($recipient->id, $amount, 'admin_wallet_transfer');

            if (MoneyService::compare((string) $senderAfter->current_balance, $balanceAfterSender) !== 0
                || MoneyService::compare((string) $recipientAfter->current_balance, $balanceAfterRecipient) !== 0) {
                throw new RuntimeException('نتيجة المحفظة لا تطابق القيد المخطّط؛ رُدّت العملية كاملة');
            }

            return [
                'transaction_id' => $creditId,
                'ledger_entry_ulid' => (string) $entry->entry_ulid,
                'duplicate' => false,
            ];
        }, 3);
    }

    private function assertWalletMatchesLedger(string $walletBalance, int $ledgerAccountId, string $label): void
    {
        $ledgerBalance = $this->ledger->computeBalanceFromLines($ledgerAccountId);
        if (MoneyService::compare($walletBalance, $ledgerBalance) !== 0) {
            throw new RuntimeException("لا يمكن تحويل رصيد {$label} فوق انحراف قائم بين المحفظة والدفتر");
        }
    }

    private function openWalletBalanceIfPristine(int $userId, string $reason): void
    {
        if ($this->ledger->walletHasLedgerHistory($userId)) {
            return;
        }

        $this->ledger->openWalletBalance($userId, $reason);
    }
}
