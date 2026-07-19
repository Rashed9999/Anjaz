<?php

namespace App\Services;

use App\Models\CorporateAccount;
use App\Models\CorporateAccountMember;
use App\Models\CorporateAccountMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-CORPORATE-ACCOUNTS-001 — إدارة حسابات الشركات (B2B) بحدّ ائتمان.
 *
 * دفتر ائتمان آمن: كل حركة تُقفل صفّ الحساب لمنع السباق، وتفرض حدّ الائتمان
 * وحدّ العضو، وتتعقّب الرصيد المستحقّ. الشراء يزيد الدَّين، السداد يقلّه.
 */
class CorporateAccountService
{
    /** إنشاء حساب شركة جديد لدى التاجر. */
    public function createAccount(User $merchant, array $data): CorporateAccount
    {
        $name = trim((string) ($data['company_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('اسم الشركة مطلوب');
        }

        return CorporateAccount::create([
            'merchant_user_id' => $merchant->id,
            'account_code' => $this->generateCode(),
            'company_name' => $name,
            'contact_person' => $data['contact_person'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'credit_limit' => MoneyService::normalize($data['credit_limit'] ?? '0'),
            'monthly_limit' => isset($data['monthly_limit']) ? MoneyService::normalize($data['monthly_limit']) : null,
            'current_balance' => '0.0000',
            'status' => 'active',
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);
    }

    public function updateAccount(CorporateAccount $account, array $data): CorporateAccount
    {
        foreach (['company_name', 'contact_person', 'contact_phone', 'tax_number', 'status'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null) {
                $account->{$f} = $data[$f];
            }
        }
        if (isset($data['credit_limit'])) {
            $account->credit_limit = MoneyService::normalize($data['credit_limit']);
        }
        if (array_key_exists('monthly_limit', $data)) {
            $account->monthly_limit = $data['monthly_limit'] !== null
                ? MoneyService::normalize($data['monthly_limit']) : null;
        }
        $account->save();
        return $account->fresh();
    }

    public function addMember(CorporateAccount $account, array $data): CorporateAccountMember
    {
        $name = trim((string) ($data['member_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('اسم العضو مطلوب');
        }
        $identifier = isset($data['identifier']) ? trim((string) $data['identifier']) : null;
        if ($identifier !== null && $identifier !== '') {
            $dup = CorporateAccountMember::where('corporate_account_id', $account->id)
                ->where('identifier', $identifier)->exists();
            if ($dup) {
                throw new InvalidArgumentException('المعرّف مستخدم مسبقاً لهذا الحساب');
            }
        }

        return CorporateAccountMember::create([
            'corporate_account_id' => $account->id,
            'member_name' => $name,
            'identifier' => $identifier ?: null,
            'per_txn_limit' => isset($data['per_txn_limit']) ? MoneyService::normalize($data['per_txn_limit']) : null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * تسجيل شراء على حساب الشركة (يزيد الدَّين ضمن الحدّ).
     */
    public function recordCharge(
        CorporateAccount $account,
        string $amount,
        ?CorporateAccountMember $member = null,
        ?int $createdBy = null,
        ?string $note = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $referenceNumber = null,
    ): CorporateAccountMovement {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ الشراء يجب أن يكون موجباً');
        }
        if ($member && $member->per_txn_limit !== null
            && MoneyService::gt($amount, (string) $member->per_txn_limit)) {
            throw new RuntimeException('المبلغ يتجاوز حدّ العضو للعملية');
        }

        return DB::transaction(function () use ($account, $amount, $member, $createdBy, $note, $referenceType, $referenceId, $referenceNumber) {
            $locked = CorporateAccount::where('id', $account->id)->lockForUpdate()->first();
            if (!$locked) throw new RuntimeException('الحساب غير موجود');
            if (!$locked->isActive()) throw new RuntimeException('حساب الشركة موقوف');

            $newBalance = MoneyService::add((string) $locked->current_balance, $amount);
            // فرض حدّ الائتمان
            if (MoneyService::gt($newBalance, (string) $locked->credit_limit)) {
                throw new RuntimeException('تجاوز حدّ الائتمان — المتاح: ' . $locked->availableCredit() . ' ر.ي');
            }

            $movement = $this->createMovement($locked, 'charge', $amount, $newBalance, [
                'member_id' => $member?->id,
                'created_by' => $createdBy,
                'note' => $note,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
            ]);

            $locked->update(['current_balance' => MoneyService::normalize($newBalance)]);
            if ($member) $member->update(['last_used_at' => now()]);

            return $movement;
        });
    }

    /** تسجيل سداد (تسوية) — يقلّ الدَّين. لا يجعل الرصيد سالباً. */
    public function recordSettlement(
        CorporateAccount $account,
        string $amount,
        ?int $createdBy = null,
        ?string $note = null,
        ?string $referenceNumber = null,
    ): CorporateAccountMovement {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ السداد يجب أن يكون موجباً');
        }

        return DB::transaction(function () use ($account, $amount, $createdBy, $note, $referenceNumber) {
            $locked = CorporateAccount::where('id', $account->id)->lockForUpdate()->first();
            if (!$locked) throw new RuntimeException('الحساب غير موجود');

            $newBalance = MoneyService::sub((string) $locked->current_balance, $amount);
            if ((float) $newBalance < 0) {
                throw new RuntimeException('مبلغ السداد أكبر من المستحقّ');
            }

            $movement = $this->createMovement($locked, 'payment', '-' . $amount, $newBalance, [
                'created_by' => $createdBy,
                'note' => $note,
                'reference_number' => $referenceNumber,
            ]);

            $locked->update([
                'current_balance' => MoneyService::normalize($newBalance),
                'last_settlement_at' => now(),
            ]);

            return $movement;
        });
    }

    /** @return array{account:CorporateAccount, movements:\Illuminate\Support\Collection} */
    public function statement(CorporateAccount $account, int $limit = 200): array
    {
        $movements = CorporateAccountMovement::where('corporate_account_id', $account->id)
            ->orderByDesc('id')->limit($limit)->get();
        return ['account' => $account->fresh(), 'movements' => $movements];
    }

    /** ملخّص لوحة الشركات لدى تاجر. */
    public function dashboard(int $merchantId): array
    {
        $accounts = CorporateAccount::where('merchant_user_id', $merchantId)->get();
        $totalOutstanding = '0';
        foreach ($accounts as $a) {
            $totalOutstanding = MoneyService::add($totalOutstanding, (string) $a->current_balance);
        }
        return [
            'accounts_count' => $accounts->count(),
            'active_count' => $accounts->where('status', 'active')->count(),
            'total_outstanding' => MoneyService::normalize($totalOutstanding),
        ];
    }

    private function createMovement(CorporateAccount $account, string $type, string $signedAmount, string $balanceAfter, array $opts): CorporateAccountMovement
    {
        return CorporateAccountMovement::create([
            'movement_ulid' => (string) Str::ulid(),
            'corporate_account_id' => $account->id,
            'member_id' => $opts['member_id'] ?? null,
            'type' => $type,
            'amount' => $signedAmount,
            'balance_after' => MoneyService::normalize($balanceAfter),
            'due_date' => $opts['due_date'] ?? null,
            'reference_type' => $opts['reference_type'] ?? null,
            'reference_id' => $opts['reference_id'] ?? null,
            'reference_number' => $opts['reference_number'] ?? null,
            'note' => $opts['note'] ?? null,
            'created_by_user_id' => $opts['created_by'] ?? null,
            'zone_code' => $account->zone_code,
        ]);
    }

    private function generateCode(): string
    {
        do {
            $code = 'CORP-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (CorporateAccount::where('account_code', $code)->exists());
        return $code;
    }
}
