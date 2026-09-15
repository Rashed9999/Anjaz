<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-003
 *
 * المصدر التنفيذي لحدود حركة العميل الفردي. يحسب أصل الحركة فقط، ويعامل
 * الحركة المحجوزة كاستخدام حتى تُلغى، منعاً لتجاوز الحدود بطلبات متزامنة.
 */
class CustomerTurnoverService
{
    public const RESERVED = 'reserved';
    public const POSTED = 'posted';
    public const RELEASED = 'released';

    public function recordPosted(
        User|int $user,
        string $amount,
        string $direction,
        string $sourceKey,
        ?int $transactionRowId = null,
        ?string $transactionId = null,
        ?string $transactionType = null,
        Carbon|string|null $occurredAt = null,
        bool $enforceLimits = true,
    ): void {
        $this->record(
            user: $user,
            amount: $amount,
            direction: $direction,
            sourceKey: $sourceKey,
            status: self::POSTED,
            transactionRowId: $transactionRowId,
            transactionId: $transactionId,
            transactionType: $transactionType,
            occurredAt: $occurredAt,
            enforceLimits: $enforceLimits,
        );
    }

    public function reserve(
        User|int $user,
        string $amount,
        string $direction,
        string $sourceKey,
        ?string $transactionType = null,
        Carbon|string|null $occurredAt = null,
    ): void {
        $this->record(
            user: $user,
            amount: $amount,
            direction: $direction,
            sourceKey: $sourceKey,
            status: self::RESERVED,
            transactionType: $transactionType,
            occurredAt: $occurredAt,
            enforceLimits: true,
        );
    }

    public function finalize(string $sourceKey): void
    {
        if (!Schema::hasTable('customer_turnover_usage')) return;

        DB::table('customer_turnover_usage')
            ->where('source_key', $sourceKey)
            ->where('status', self::RESERVED)
            ->update([
                'status' => self::POSTED,
                'finalized_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function finalizeWithAmount(string $sourceKey, string $finalAmount): void
    {
        if (!Schema::hasTable('customer_turnover_usage')) return;
        if (bccomp($finalAmount, '0', 4) < 0) {
            throw new RuntimeException('TURNOVER_FINAL_AMOUNT_INVALID');
        }

        $row = DB::table('customer_turnover_usage')
            ->where('source_key', $sourceKey)
            ->lockForUpdate()
            ->first();
        if (!$row || $row->status === self::RELEASED) return;

        if (bccomp($finalAmount, (string) $row->principal_amount, 4) > 0) {
            throw new RuntimeException('TURNOVER_FINAL_AMOUNT_CANNOT_EXCEED_RESERVED');
        }
        if (bccomp($finalAmount, '0', 4) === 0) {
            $this->release($sourceKey, 'final amount is zero');
            return;
        }

        DB::table('customer_turnover_usage')->where('id', $row->id)->update([
            'principal_amount' => $finalAmount,
            'status' => self::POSTED,
            'finalized_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function release(string $sourceKey, string $reason): void
    {
        if (!Schema::hasTable('customer_turnover_usage')) return;

        DB::table('customer_turnover_usage')
            ->where('source_key', $sourceKey)
            ->whereIn('status', [self::RESERVED, self::POSTED])
            ->update([
                'status' => self::RELEASED,
                'released_at' => now(),
                'release_reason' => mb_substr(trim($reason), 0, 255),
                'updated_at' => now(),
            ]);
    }

    public function totalSince(int $userId, Carbon $since): string
    {
        if (!Schema::hasTable('customer_turnover_usage')) return '0';

        $total = DB::table('customer_turnover_usage')
            ->where('user_id', $userId)
            ->whereIn('status', [self::RESERVED, self::POSTED])
            ->where('occurred_at', '>=', $since)
            ->sum('principal_amount');

        return (string) ($total ?: '0');
    }

    private function record(
        User|int $user,
        string $amount,
        string $direction,
        string $sourceKey,
        string $status,
        ?int $transactionRowId = null,
        ?string $transactionId = null,
        ?string $transactionType = null,
        Carbon|string|null $occurredAt = null,
        bool $enforceLimits = true,
    ): void {
        if (!Schema::hasTable('customer_turnover_usage')) return;
        if (!in_array($direction, ['in', 'out'], true)) {
            throw new RuntimeException('TURNOVER_DIRECTION_INVALID');
        }
        if (bccomp($amount, '0', 4) <= 0) return;

        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        if ($userId < 1) return;

        /** @var User|null $locked */
        $locked = User::query()->whereKey($userId)->lockForUpdate()->first();
        if (!$locked || (int) $locked->type !== 2) return;

        if (DB::table('customer_turnover_usage')->where('source_key', $sourceKey)->exists()) {
            return;
        }

        if ($enforceLimits) {
            $tiers = app(KycTierService::class);
            $tier = $tiers->effectiveTier($locked);

            // Tier 0 والحسابات القديمة غير المهاجرة لا تُحوَّل إلى رفض من
            // Observer تاريخي؛ بوابة التفعيل نفسها تمنعها من الحركة الجديدة.
            if ($tier > 0) {
                $limits = $tiers->getLimits($tier);

                $dayRows = DB::table('customer_turnover_usage')
                    ->where('user_id', $userId)
                    ->whereIn('status', [self::RESERVED, self::POSTED])
                    ->where('occurred_at', '>=', now()->startOfDay())
                    ->lockForUpdate()
                    ->pluck('principal_amount');
                $monthRows = DB::table('customer_turnover_usage')
                    ->where('user_id', $userId)
                    ->whereIn('status', [self::RESERVED, self::POSTED])
                    ->where('occurred_at', '>=', now()->startOfMonth())
                    ->lockForUpdate()
                    ->pluck('principal_amount');

                $daily = $this->sumValues($dayRows->all());
                $monthly = $this->sumValues($monthRows->all());

                if (bccomp(bcadd($daily, $amount, 4), (string) $limits['max_daily_total'], 4) > 0) {
                    throw new RuntimeException('هذه العملية ستتجاوز حد إجمالي الحركة اليومي لمستوى حسابك.');
                }
                if (bccomp(bcadd($monthly, $amount, 4), (string) $limits['max_monthly_total'], 4) > 0) {
                    throw new RuntimeException('هذه العملية ستتجاوز حد إجمالي الحركة الشهري لمستوى حسابك. أكمل التوثيق لرفع الحد.');
                }
            }
        }

        $at = $occurredAt instanceof Carbon
            ? $occurredAt
            : ($occurredAt ? Carbon::parse($occurredAt) : now());

        DB::table('customer_turnover_usage')->insert([
            'user_id' => $userId,
            'source_key' => $sourceKey,
            'transaction_row_id' => $transactionRowId,
            'transaction_id' => $transactionId,
            'transaction_type' => $transactionType,
            'direction' => $direction,
            'principal_amount' => $amount,
            'status' => $status,
            'occurred_at' => $at,
            'finalized_at' => $status === self::POSTED ? $at : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int,mixed> $values */
    private function sumValues(array $values): string
    {
        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, (string) $value, 4);
        }
        return $sum;
    }
}
