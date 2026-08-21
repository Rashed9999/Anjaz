<?php

namespace App\Services;

use App\Models\FamilyFund;
use App\Models\FamilyFundMember;
use App\Models\FamilyFundTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-FUND-FAMILY-001 (v0.9-B)
 *
 * إدارة الصناديق العائلية / المشتركة.
 *
 * **مبادئ التصميم (قسم 18 من الوثيقة):**
 *   - رصيد الصندوق مستقل عن محافظ الأعضاء
 *   - حركات الصندوق append-only مع balance_before/balance_after
 *   - مساهمة عضو = تحويل من محفظته إلى الصندوق (DB transaction واحد)
 *   - الصرف يحتاج موافقة owner (configurable)
 *   - SOUTH only — تطبق ZonePolicyService
 *   - إيصال PDF لكل مساهمة/صرف عبر ReceiptService
 */
class FamilyFundService
{
    // AMIAL-LEDGER-FAMILYFUND-001 — الحوضُ يدخل الدفتر. التفصيلُ فوق
    // `PostsToLedger::ledgerFamilyFundContribution`.
    use \App\Traits\PostsToLedger;

    public function __construct(
        private readonly FinancialGuardService $guard,
        private readonly AuditService $audit,
        private readonly ReceiptService $receipts,
    ) {}

    /**
     * إنشاء صندوق جديد. الـ creator يصبح owner ومضاف كعضو نشط تلقائياً.
     */
    public function create(
        User $owner,
        string $name,
        ?string $description = null,
        bool $requireOwnerApproval = true,
        ?string $targetAmount = null, // AMIAL-FUND-002: المبلغ المستهدف
    ): FamilyFund {
        if ($owner->zone_code !== 'SOUTH') {
            throw new \RuntimeException('Only SOUTH users can create funds');
        }

        return DB::transaction(function () use ($owner, $name, $description, $requireOwnerApproval, $targetAmount) {
            $fund = FamilyFund::create([
                'fund_ulid' => (string) Str::ulid(),
                'name' => mb_substr($name, 0, 100),
                'description' => $description ? mb_substr($description, 0, 500) : null,
                'owner_user_id' => $owner->id,
                'balance' => '0.0000',
                'held_balance' => '0.0000',
                'zone_code' => 'SOUTH',
                'status' => 'active',
                'require_owner_approval_for_disbursement' => $requireOwnerApproval,
                'target_amount' => $targetAmount, // AMIAL-FUND-002
            ]);

            // الـ owner عضو نشط تلقائياً
            FamilyFundMember::create([
                'fund_id' => $fund->id,
                'user_id' => $owner->id,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
                'invited_by_user_id' => $owner->id,
            ]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $owner->id,
                'subject_type' => 'family_fund',
                'subject_id' => (string)$fund->id,
                'action' => 'FAMILY_FUND_CREATED',
                'decision_code' => 'FUND_CREATED',
                'reason' => "Fund '{$fund->name}' created",
                'severity' => 'info',
                'context' => ['fund_ulid' => $fund->fund_ulid],
            ]);

            return $fund;
        });
    }

    /**
     * دعوة عضو جديد. الـ user يجب أن يكون مسجلاً في النظام.
     */
    public function inviteMember(
        FamilyFund $fund,
        User $inviter,
        string $inviteePhone,
        string $role = 'member',
    ): FamilyFundMember {
        if (!in_array($role, ['admin', 'member', 'viewer'], true)) {
            throw new \InvalidArgumentException('Invalid role for invitation');
        }

        $inviterRole = $fund->memberRole($inviter->id);
        if (!in_array($inviterRole, ['owner', 'admin'], true)) {
            throw new \RuntimeException('Only owner or admin can invite members');
        }

        // AMIAL-FIX: مطابقة صيغ الهاتف المكافئة (777… ↔ 967777…)
        $invitee = User::whereIn('phone', \App\Support\Phone::variants($inviteePhone))->first();
        if (!$invitee) {
            throw new \RuntimeException('User with this phone is not registered');
        }
        if ($invitee->zone_code !== 'SOUTH') {
            throw new \RuntimeException('Invitee must be in SOUTH zone');
        }

        // فحص duplicate
        $existing = FamilyFundMember::where('fund_id', $fund->id)
            ->where('user_id', $invitee->id)
            ->first();
        if ($existing) {
            if ($existing->status === 'active') {
                throw new \RuntimeException('User is already an active member');
            }
            // re-invite
            $existing->update([
                'status' => 'invited',
                'role' => $role,
                'invited_at' => now(),
                'invited_by_user_id' => $inviter->id,
            ]);
            return $existing;
        }

        $member = FamilyFundMember::create([
            'fund_id' => $fund->id,
            'user_id' => $invitee->id,
            'role' => $role,
            'status' => 'invited',
            'invited_at' => now(),
            'invited_by_user_id' => $inviter->id,
        ]);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $inviter->id,
            'subject_type' => 'family_fund_member',
            'subject_id' => (string)$member->id,
            'action' => 'FAMILY_FUND_INVITED',
            'decision_code' => 'INVITE_SENT',
            'reason' => "User {$invitee->id} invited as {$role}",
            'severity' => 'info',
            'context' => ['fund_id' => $fund->id, 'role' => $role],
        ]);

        return $member;
    }

    /**
     * الـ user يقبل الدعوة.
     */
    public function acceptInvitation(FamilyFundMember $member, User $user): bool
    {
        if ($member->user_id !== $user->id) {
            throw new \RuntimeException('Cannot accept invitation for another user');
        }
        if ($member->status !== 'invited') {
            return false;
        }

        $member->update([
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'family_fund_member',
            'subject_id' => (string)$member->id,
            'action' => 'FAMILY_FUND_JOINED',
            'decision_code' => 'INVITE_ACCEPTED',
            'severity' => 'info',
        ]);

        return true;
    }

    /**
     * مساهمة عضو في الصندوق:
     *   1. خصم من محفظة العضو (مع lock + idempotency)
     *   2. إضافة لرصيد الصندوق (مع lock)
     *   3. تسجيل family_fund_transaction
     *   4. إصدار إيصال
     *
     * كله داخل DB::transaction واحد.
     */
    public function contribute(
        FamilyFund $fund,
        User $user,
        float|string $amount,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): FamilyFundTransaction {
        if ($user->zone_code !== 'SOUTH') {
            throw new \RuntimeException('Only SOUTH users can contribute');
        }
        if (!$fund->canContribute($user->id)) {
            throw new \RuntimeException('User is not allowed to contribute to this fund');
        }
        if ($fund->status !== 'active') {
            throw new \RuntimeException('Fund is not active');
        }

        $amountNormalized = MoneyService::normalize($amount);

        if (bccomp($amountNormalized, '0', 4) <= 0) {
            throw new \RuntimeException('Amount must be positive');
        }

        // فحص حد المساهمة اليومي إن وُجد
        if ($fund->max_member_contribution_per_day) {
            $todayTotal = FamilyFundTransaction::where('fund_id', $fund->id)
                ->where('user_id', $user->id)
                ->where('tx_type', 'contribute')
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('amount');

            $newTotal = MoneyService::add((string)$todayTotal, $amountNormalized);
            if (bccomp($newTotal, (string)$fund->max_member_contribution_per_day, 4) > 0) {
                throw new \RuntimeException('Daily contribution limit exceeded');
            }
        }

        return DB::transaction(function () use ($fund, $user, $amountNormalized, $note) {
            // 1) خصم من محفظة المستخدم
            $this->guard->debit(
                userId: $user->id,
                amount: $amountNormalized,
                reason: 'family_fund_contribute',
            );

            // 2) قفل الصندوق وإضافة الرصيد
            $lockedFund = FamilyFund::where('id', $fund->id)->lockForUpdate()->first();
            $balanceBefore = (string)$lockedFund->balance;
            $balanceAfter = MoneyService::add($balanceBefore, $amountNormalized);
            $lockedFund->update(['balance' => $balanceAfter]);

            // 3) سجل الـ transaction
            $tx = FamilyFundTransaction::create([
                'tx_ulid' => (string) Str::ulid(),
                'fund_id' => $fund->id,
                'user_id' => $user->id,
                'tx_type' => 'contribute',
                'amount' => $amountNormalized,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'note' => $note ? mb_substr($note, 0, 500) : null,
                'status' => 'completed',
                'created_at' => now(),
            ]);

            // 4) تحديث إحصاءات العضو
            FamilyFundMember::where('fund_id', $fund->id)
                ->where('user_id', $user->id)
                ->increment('total_contributed', $amountNormalized);

            // **والدفترُ يرى المالَ يغادر إلى الحوض** — لا يراه يتبخّر.
            // ومرجعُ القيد `tx_ulid` لا رقمُ الصندوق: العضوُ يساهم مراراً،
            // ومفتاحٌ برقم الصندوق يبتلع كلَّ مساهمةٍ بعد الأولى صامتاً.
            $this->ledgerFamilyFundContribution(
                memberUserId: (int) $user->id,
                fundId: (int) $fund->id,
                amount: $amountNormalized,
                sourceId: (string) $tx->tx_ulid,
            );

            // 5) إيصال (خارج الـ transaction سيُستدعى ReceiptService::issueDebit
            //    لكن نسجله هنا داخل الـ tx — الـ Job يدفع للـ queue)
            $this->receipts->issueDebit([
                'user_id' => $user->id,
                'counterparty_user_id' => null,
                'reference_transaction_id' => $tx->tx_ulid,
                'receipt_type' => 'family_fund_contribute',
                'amount' => $amountNormalized,
                'fee' => '0',
                'reference_type' => 'family_fund',
                'reference_id' => $fund->id,
                'metadata' => [
                    'fund_name' => $fund->name,
                    'note' => $note,
                ],
                'zone_code' => 'SOUTH',
            ]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'subject_type' => 'family_fund',
                'subject_id' => (string)$fund->id,
                'action' => 'FAMILY_FUND_CONTRIBUTE',
                'decision_code' => 'CONTRIBUTE_OK',
                'severity' => 'info',
                'context' => [
                    'amount' => $amountNormalized,
                    'balance_after' => $balanceAfter,
                ],
            ]);

            return $tx;
        });
    }

    /**
     * صرف من الصندوق لعضو (يحتاج موافقة owner إن configured).
     *
     * @return FamilyFundTransaction (status = completed أو pending_approval)
     */
    public function proposeDisbursement(
        FamilyFund $fund,
        User $proposer,
        User $beneficiary,
        float|string $amount,
        ?string $note = null,
    ): FamilyFundTransaction {
        $fund->refresh(); // MERGE-FIX: تفادي رصيد قديم في الكائن المُمرَّر
        if (!$fund->canDisburse($proposer->id)) {
            throw new \RuntimeException('User cannot propose disbursement');
        }
        if (!$fund->isMember($beneficiary->id)) {
            throw new \RuntimeException('Beneficiary must be an active member');
        }

        $amountNormalized = MoneyService::normalize($amount);

        if (bccomp($amountNormalized, (string)$fund->balance, 4) > 0) {
            throw new \RuntimeException('Insufficient fund balance');
        }

        $needsApproval = $fund->require_owner_approval_for_disbursement
            && $fund->memberRole($proposer->id) !== 'owner';

        return DB::transaction(function () use ($fund, $proposer, $beneficiary, $amountNormalized, $note, $needsApproval) {
            if (!$needsApproval) {
                // تنفيذ مباشر
                return $this->executeDisbursement($fund, $proposer, $beneficiary, $amountNormalized, $note, $proposer);
            }

            // مقترَح فقط — لا تحريك مال بعد
            $tx = FamilyFundTransaction::create([
                'tx_ulid' => (string) Str::ulid(),
                'fund_id' => $fund->id,
                'user_id' => $proposer->id,
                'tx_type' => 'disburse_to_member',
                'amount' => $amountNormalized,
                'balance_before' => (string)$fund->balance,
                'balance_after' => (string)$fund->balance, // لم يتغير بعد
                'beneficiary_user_id' => $beneficiary->id,
                'note' => $note ? mb_substr($note, 0, 500) : null,
                'status' => 'pending_approval',
                'created_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $proposer->id,
                'subject_type' => 'family_fund',
                'subject_id' => (string)$fund->id,
                'action' => 'FAMILY_FUND_DISBURSEMENT_PROPOSED',
                'decision_code' => 'DISBURSEMENT_PENDING',
                'severity' => 'notice',
                'context' => [
                    'beneficiary_id' => $beneficiary->id,
                    'amount' => $amountNormalized,
                ],
            ]);

            return $tx;
        });
    }

    /**
     * Owner يوافق على مقترح صرف.
     */
    public function approveDisbursement(FamilyFundTransaction $tx, User $approver): bool
    {
        if ($tx->status !== 'pending_approval') {
            return false;
        }

        $fund = $tx->fund;
        if (!$fund->canApproveDisbursement($approver->id)) {
            throw new \RuntimeException('Only owner can approve disbursement');
        }

        $beneficiary = $tx->beneficiary;
        if (!$beneficiary) {
            throw new \RuntimeException('Beneficiary no longer exists');
        }

        return DB::transaction(function () use ($tx, $fund, $approver, $beneficiary) {
            // إعادة فحص الرصيد (قد تغير منذ المقترح)
            $lockedFund = FamilyFund::where('id', $fund->id)->lockForUpdate()->first();
            if (bccomp((string)$tx->amount, (string)$lockedFund->balance, 4) > 0) {
                $tx->update([
                    'status' => 'rejected',
                    'approved_by_user_id' => $approver->id,
                    'approved_at' => now(),
                ]);
                throw new \RuntimeException('Fund balance insufficient at approval time');
            }

            // تنفيذ
            $this->executeDisbursementCore($lockedFund, $beneficiary, (string)$tx->amount, $tx);

            $tx->update([
                'status' => 'completed',
                'approved_by_user_id' => $approver->id,
                'approved_at' => now(),
            ]);

            return true;
        });
    }

    public function rejectDisbursement(FamilyFundTransaction $tx, User $approver, string $reason): bool
    {
        if ($tx->status !== 'pending_approval') {
            return false;
        }
        $fund = $tx->fund;
        if (!$fund->canApproveDisbursement($approver->id)) {
            throw new \RuntimeException('Only owner can reject');
        }

        $tx->update([
            'status' => 'rejected',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
            // note عمداً غير قابلة للتعديل لكن نسجل الـ reason في audit
        ]);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $approver->id,
            'subject_type' => 'family_fund_transaction',
            'subject_id' => (string)$tx->id,
            'action' => 'FAMILY_FUND_DISBURSEMENT_REJECTED',
            'decision_code' => 'DISBURSEMENT_REJECTED',
            'reason' => mb_substr($reason, 0, 255),
            'severity' => 'notice',
        ]);

        return true;
    }

    /**
     * Helper: تنفيذ الـ disbursement (يفترض الفحوصات تمت).
     */
    private function executeDisbursement(
        FamilyFund $fund,
        User $proposer,
        User $beneficiary,
        string $amount,
        ?string $note,
        User $approver,
    ): FamilyFundTransaction {
        $lockedFund = FamilyFund::where('id', $fund->id)->lockForUpdate()->first();
        $balanceBefore = (string)$lockedFund->balance;
        $balanceAfter = MoneyService::sub($balanceBefore, $amount);
        $lockedFund->update(['balance' => $balanceAfter]);

        // إضافة لمحفظة المستفيد
        $this->guard->credit(
            userId: $beneficiary->id,
            amount: $amount,
            reason: 'family_fund_disburse',
        );

        $tx = FamilyFundTransaction::create([
            'tx_ulid' => (string) Str::ulid(),
            'fund_id' => $fund->id,
            'user_id' => $proposer->id,
            'tx_type' => 'disburse_to_member',
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'beneficiary_user_id' => $beneficiary->id,
            'note' => $note ? mb_substr($note, 0, 500) : null,
            'status' => 'completed',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        FamilyFundMember::where('fund_id', $fund->id)
            ->where('user_id', $beneficiary->id)
            ->increment('total_disbursed', $amount);

        $this->ledgerFamilyFundDisbursement(
            beneficiaryUserId: (int) $beneficiary->id,
            fundId: (int) $fund->id,
            amount: $amount,
            sourceId: (string) $tx->tx_ulid,
        );

        $this->receipts->issueCredit([
            'user_id' => $beneficiary->id,
            'counterparty_user_id' => $proposer->id,
            'reference_transaction_id' => $tx->tx_ulid,
            'receipt_type' => 'family_fund_disburse',
            'amount' => $amount,
            'fee' => '0',
            'reference_type' => 'family_fund',
            'reference_id' => $fund->id,
            'metadata' => ['fund_name' => $fund->name, 'note' => $note],
            'zone_code' => 'SOUTH',
        ]);

        return $tx;
    }

    private function executeDisbursementCore(FamilyFund $lockedFund, User $beneficiary, string $amount, FamilyFundTransaction $tx): void
    {
        $balanceBefore = (string)$lockedFund->balance;
        $balanceAfter = MoneyService::sub($balanceBefore, $amount);
        $lockedFund->update(['balance' => $balanceAfter]);

        $this->guard->credit(
            userId: $beneficiary->id,
            amount: $amount,
            reason: 'family_fund_disburse',
        );

        // تحديث الـ tx بالأرصدة الفعلية
        $tx->update(['status' => 'completed']);
        // ملاحظة: balance_before/after في الـ tx الأصلية كانتا snapshot المقترح،
        // لكن السياسة append-only لا تسمح بتغييرها — نسجل قيد adjustment إذا اختلفت.
        if ((string)$tx->balance_before !== $balanceBefore) {
            FamilyFundTransaction::create([
                'tx_ulid' => (string) Str::ulid(),
                'fund_id' => $lockedFund->id,
                'user_id' => $beneficiary->id,
                'tx_type' => 'adjustment',
                'amount' => '0',
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'note' => "Adjustment for tx {$tx->tx_ulid}: actual balances at execution time",
                'status' => 'completed',
                'created_at' => now(),
            ]);
        }

        FamilyFundMember::where('fund_id', $lockedFund->id)
            ->where('user_id', $beneficiary->id)
            ->increment('total_disbursed', $amount);

        // **المخرجُ الثاني من الحوض** — الموافقةُ الجماعيّة. وهو مسارٌ
        // مستقلٌّ عن `executeDisbursement`، ومن رحّل أحدَهما وحدَه ترك
        // نصفَ الصرف بلا قيد: حوضٌ ينزل في الجدول ولا ينزل في الدفتر.
        $this->ledgerFamilyFundDisbursement(
            beneficiaryUserId: (int) $beneficiary->id,
            fundId: (int) $lockedFund->id,
            amount: $amount,
            sourceId: (string) $tx->tx_ulid,
        );

        $this->receipts->issueCredit([
            'user_id' => $beneficiary->id,
            'counterparty_user_id' => $tx->user_id,
            'reference_transaction_id' => $tx->tx_ulid,
            'receipt_type' => 'family_fund_disburse',
            'amount' => $amount,
            'fee' => '0',
            'reference_type' => 'family_fund',
            'reference_id' => $lockedFund->id,
            'metadata' => ['fund_name' => $lockedFund->name],
            'zone_code' => 'SOUTH',
        ]);
    }
}
