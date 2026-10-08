<?php

namespace App\Services;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgram;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AMIAL-LOYALTY-001 — منطق برنامج الولاء والنقاط.
 *
 *   earn()   — تُستدعى مركزياً عند إتمام بيع (كل القطاعات) إن كان للتاجر برنامج
 *              مُفعّل والعميل معروف بالهاتف. النقاط = (المبلغ/100) × معدّل الكسب.
 *   redeem() — تحويل نقاط إلى خصم بالريال (قيمة النقطة × عددها) مع خصم الرصيد.
 *   adjust() — تعديل يدوي (منح/سحب) من التاجر.
 */
class LoyaltyService
{
    public function program(User $merchant): LoyaltyProgram
    {
        return LoyaltyProgram::firstOrCreate(
            ['merchant_user_id' => $merchant->id],
            ['zone_code' => $merchant->zone_code ?? 'SOUTH'],
        );
    }

    public function saveProgram(User $merchant, array $data): LoyaltyProgram
    {
        $p = $this->program($merchant);
        $p->fill([
            'is_active' => (bool) ($data['is_active'] ?? $p->is_active),
            'earn_points_per_100' => $data['earn_points_per_100'] ?? $p->earn_points_per_100,
            'redeem_value_per_point' => $data['redeem_value_per_point'] ?? $p->redeem_value_per_point,
            'min_redeem_points' => (int) ($data['min_redeem_points'] ?? $p->min_redeem_points),
        ]);
        $p->save();
        return $p;
    }

    public function accountFor(User $merchant, string $phone, ?string $name = null): LoyaltyAccount
    {
        $canonical = Phone::canonical($phone);
        return LoyaltyAccount::firstOrCreate(
            ['merchant_user_id' => $merchant->id, 'customer_phone' => $canonical],
            ['customer_name' => $name, 'zone_code' => $merchant->zone_code ?? 'SOUTH'],
        );
    }

    /**
     * كسب نقاط عند بيع مُتمّ. آمنة الاستدعاء دائماً — تتجاهل بصمت إن لا برنامج
     * مُفعّل أو لا هاتف عميل أو مبلغ غير موجب (لا تُعطّل البيع أبداً).
     */
    public function earnForSale(User $merchant, ?string $customerPhone, ?string $customerName, string $amount, ?string $saleUlid = null): ?LoyaltyMovement
    {
        if (empty($customerPhone)) return null;
        $program = LoyaltyProgram::where('merchant_user_id', $merchant->id)->where('is_active', true)->first();
        if (!$program) return null;

        $amount = (float) $amount;
        if ($amount <= 0) return null;

        $points = round(($amount / 100.0) * (float) $program->earn_points_per_100, 2);
        if ($points <= 0) return null;

        return DB::transaction(function () use ($merchant, $customerPhone, $customerName, $points, $saleUlid) {
            $account = $this->lockAccount($merchant, $customerPhone, $customerName);
            $account->points_balance = (float) $account->points_balance + $points;
            $account->total_earned = (float) $account->total_earned + $points;
            if ($customerName && empty($account->customer_name)) $account->customer_name = $customerName;
            $account->save();

            return LoyaltyMovement::create([
                'loyalty_account_id' => $account->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => $account->points_balance,
                'sale_ulid' => $saleUlid,
                'note' => 'كسب من بيع',
                'created_by' => $merchant->id,
            ]);
        });
    }

    /**
     * استبدال نقاط بخصم بالريال. يعيد قيمة الخصم (ر.ي). يرمي عند عدم الكفاية.
     */
    public function redeem(User $merchant, string $phone, float $points, ?int $createdBy = null, ?string $saleUlid = null): array
    {
        $program = LoyaltyProgram::where('merchant_user_id', $merchant->id)->where('is_active', true)->first();
        if (!$program) throw new RuntimeException('برنامج الولاء غير مُفعّل');
        if ($points <= 0) throw new RuntimeException('عدد النقاط غير صحيح');
        if ($points < (int) $program->min_redeem_points) {
            throw new RuntimeException('الحدّ الأدنى للاستبدال ' . (int) $program->min_redeem_points . ' نقطة');
        }

        return DB::transaction(function () use ($merchant, $phone, $points, $program, $createdBy, $saleUlid) {
            $account = $this->lockAccount($merchant, $phone, null);
            if ((float) $account->points_balance < $points) {
                throw new RuntimeException('رصيد النقاط غير كافٍ (المتاح: ' . (float) $account->points_balance . ')');
            }
            $discount = round($points * (float) $program->redeem_value_per_point, 4);
            $account->points_balance = (float) $account->points_balance - $points;
            $account->total_redeemed = (float) $account->total_redeemed + $points;
            $account->save();

            $mov = LoyaltyMovement::create([
                'loyalty_account_id' => $account->id,
                'type' => 'redeem',
                'points' => -$points,
                'balance_after' => $account->points_balance,
                // **الحركةُ تُوسَم ببيعتها.** واستبدالٌ بلا بيعةٍ لا
                // يُراجَع ولا يُعرَف على أيّ فاتورةٍ نزل خصمُه.
                'sale_ulid' => $saleUlid,
                'note' => $saleUlid !== null
                    ? 'استبدال نقاط على فاتورة'
                    : 'استبدال نقاط بخصم (خارج البيع)',
                'created_by' => $createdBy ?? $merchant->id,
            ]);

            return ['discount' => (string) $discount, 'movement' => $mov, 'account' => $account->fresh()];
        });
    }

    public function adjust(User $merchant, string $phone, float $points, string $note, ?int $createdBy = null): LoyaltyAccount
    {
        return DB::transaction(function () use ($merchant, $phone, $points, $note, $createdBy) {
            $account = $this->lockAccount($merchant, $phone, null);
            $newBalance = (float) $account->points_balance + $points;
            if ($newBalance < 0) throw new RuntimeException('لا يمكن أن يصبح الرصيد سالباً');
            $account->points_balance = $newBalance;
            if ($points > 0) $account->total_earned = (float) $account->total_earned + $points;
            $account->save();

            LoyaltyMovement::create([
                'loyalty_account_id' => $account->id,
                'type' => 'adjust',
                'points' => $points,
                'balance_after' => $account->points_balance,
                'note' => $note ?: 'تعديل يدوي',
                'created_by' => $createdBy ?? $merchant->id,
            ]);
            return $account->fresh();
        });
    }

    private function lockAccount(User $merchant, string $phone, ?string $name): LoyaltyAccount
    {
        $canonical = Phone::canonical($phone);
        LoyaltyAccount::firstOrCreate(
            ['merchant_user_id' => $merchant->id, 'customer_phone' => $canonical],
            ['customer_name' => $name, 'zone_code' => $merchant->zone_code ?? 'SOUTH'],
        );
        return LoyaltyAccount::where('merchant_user_id', $merchant->id)
            ->where('customer_phone', $canonical)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
