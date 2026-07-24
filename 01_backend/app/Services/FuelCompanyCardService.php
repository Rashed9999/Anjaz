<?php

namespace App\Services;

use App\CentralLogics\Helpers;

use App\Models\FuelCompanyAccount;
use App\Models\FuelCompanyCard;
use App\Models\FuelSale;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-FUEL-CARDS-001 — خدمة بطاقات الشركات.
 *
 * بطاقة = هوية لسائق/سيارة محدّدة داخل حساب شركة.
 * تستخدم لإدارة حدود يومية/شهرية وتتبّع الاستهلاك.
 */
class FuelCompanyCardService
{
    public function addCard(FuelCompanyAccount $company, array $data): FuelCompanyCard
    {
        if (empty($data['card_number'])) {
            throw new InvalidArgumentException('رقم البطاقة مطلوب');
        }

        if (FuelCompanyCard::where('company_account_id', $company->id)
            ->where('card_number', $data['card_number'])->exists()) {
            throw new InvalidArgumentException('رقم البطاقة مستخدم بالفعل');
        }

        return FuelCompanyCard::create([
            'company_account_id' => $company->id,
            'card_number' => $data['card_number'],
            'card_label' => $data['card_label'] ?? null,
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'driver_name' => $data['driver_name'] ?? null,
            'driver_phone' => $data['driver_phone'] ?? null,
            'daily_limit' => MoneyService::normalize($data['daily_limit'] ?? '0'),
            'monthly_limit' => MoneyService::normalize($data['monthly_limit'] ?? '0'),
            'is_active' => true,
        ]);
    }

    public function updateCard(FuelCompanyCard $card, array $data): FuelCompanyCard
    {
        $allowed = array_intersect_key($data, array_flip([
            'card_label', 'vehicle_plate', 'driver_name', 'driver_phone',
            'daily_limit', 'monthly_limit', 'is_active',
        ]));
        if (isset($allowed['daily_limit'])) {
            $allowed['daily_limit'] = MoneyService::normalize((string)$allowed['daily_limit']);
        }
        if (isset($allowed['monthly_limit'])) {
            $allowed['monthly_limit'] = MoneyService::normalize((string)$allowed['monthly_limit']);
        }
        $card->update($allowed);
        return $card->fresh();
    }

    /**
     * يتحقّق من حدود البطاقة قبل بيع.
     * يرفض إن تجاوزها.
     */
    public function assertCardLimits(FuelCompanyCard $card, string $saleAmount): void
    {
        if (!$card->is_active) {
            throw new RuntimeException('البطاقة غير نشطة');
        }

        // الحد اليومي
        if ((float)$card->daily_limit > 0) {
            $todaySpent = (string) FuelSale::where('company_account_id', $card->company_account_id)
                ->where('company_card_id', $card->card_number)
                ->where('created_at', '>=', now()->startOfDay())
                ->where('status', 'completed')
                ->sum('total_amount');
            $newDaily = MoneyService::add($todaySpent ?: '0', $saleAmount);
            if (MoneyService::compare($newDaily, (string)$card->daily_limit) > 0) {
                throw new RuntimeException(
                    "تجاوزت الحد اليومي للبطاقة (" . Helpers::money($card->daily_limit) . " ر.ي)"
                );
            }
        }

        // الحد الشهري
        if ((float)$card->monthly_limit > 0) {
            $monthSpent = (string) FuelSale::where('company_account_id', $card->company_account_id)
                ->where('company_card_id', $card->card_number)
                ->where('created_at', '>=', now()->startOfMonth())
                ->where('status', 'completed')
                ->sum('total_amount');
            $newMonth = MoneyService::add($monthSpent ?: '0', $saleAmount);
            if (MoneyService::compare($newMonth, (string)$card->monthly_limit) > 0) {
                throw new RuntimeException(
                    "تجاوزت الحد الشهري للبطاقة (" . Helpers::money($card->monthly_limit) . " ر.ي)"
                );
            }
        }
    }
}
