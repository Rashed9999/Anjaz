<?php

namespace App\Services;

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AMIAL-MERCHANT-INVOICE-NUMBER-001
 *
 * رقم الفاتورة الظاهر للعميل ليس معرّف قاعدة بيانات ولا جزءاً من ULID.
 * صيغة الرقم: AMY-{SECTOR}-{YEAR}-{SERIAL}. التسلسل مستقل لكل تاجر وقطاع
 * وسنة، بينما تبقى ULID والمراجع القديمة مفاتيحاً داخلية لا تتغير.
 */
class MerchantInvoiceNumberService
{
    public const QUICK_SALE = 'QS';
    public const RETAIL = 'RT';
    public const FUEL = 'FU';
    public const PHARMACY = 'PH';
    public const RESTAURANT = 'RS';
    public const WHOLESALE = 'WH';

    private const ALLOWED_SERIES = [
        self::QUICK_SALE,
        self::RETAIL,
        self::FUEL,
        self::PHARMACY,
        self::RESTAURANT,
        self::WHOLESALE,
    ];

    public function nextForMerchant(User $merchant): string
    {
        $vertical = (string) MerchantProfile::where('user_id', $merchant->id)
            ->value('business_type');

        return $this->next($merchant, match ($vertical) {
            'quick_sale' => self::QUICK_SALE,
            'fuel' => self::FUEL,
            'pharmacy' => self::PHARMACY,
            'restaurant' => self::RESTAURANT,
            'wholesale' => self::WHOLESALE,
            default => self::RETAIL,
        });
    }

    public function next(User $merchant, string $series): string
    {
        if (! in_array($series, self::ALLOWED_SERIES, true)) {
            throw new InvalidArgumentException('سلسلة رقم الفاتورة غير معروفة');
        }

        $year = (int) now()->format('Y');

        $serial = DB::transaction(function () use ($merchant, $series, $year): int {
            // insertOrIgnore يجعل إنشاء أول تسلسل آمناً عندما تبدأ فاتورتان
            // للتاجر نفسه في اللحظة ذاتها؛ بعدها lockForUpdate يخصص الرقم.
            DB::table('merchant_invoice_sequences')->insertOrIgnore([
                'merchant_user_id' => $merchant->id,
                'series' => $series,
                'calendar_year' => $year,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('merchant_invoice_sequences')
                ->where('merchant_user_id', $merchant->id)
                ->where('series', $series)
                ->where('calendar_year', $year)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw new \RuntimeException('تعذّر حجز تسلسل رقم الفاتورة');
            }

            $serial = (int) $sequence->next_number;
            DB::table('merchant_invoice_sequences')->where('id', $sequence->id)->update([
                'next_number' => $serial + 1,
                'updated_at' => now(),
            ]);

            return $serial;
        }, 3);

        return sprintf('AMY-%s-%04d-%06d', $series, $year, $serial);
    }
}
