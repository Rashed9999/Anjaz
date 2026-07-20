<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-GIFT-CARDS-001 — بطاقات الهدايا ورصيد المتجر.
 *
 *   issue()  — إصدار بطاقة برصيد وكود فريد لدى التاجر.
 *   redeem() — استبدال مبلغ من رصيد البطاقة (تُستخدم كوسيلة دفع في الكاشير).
 *   topUp()  — شحن رصيد إضافي.
 *   void()   — إلغاء البطاقة (تصفير الرصيد).
 */
class GiftCardService
{
    public function issue(User $merchant, string $amount, array $opts = []): GiftCard
    {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('قيمة البطاقة يجب أن تكون موجبة');
        }

        return DB::transaction(function () use ($merchant, $amount, $opts) {
            $card = GiftCard::create([
                'merchant_user_id' => $merchant->id,
                'code' => $this->uniqueCode($merchant->id),
                'initial_balance' => $amount,
                'balance' => $amount,
                'status' => 'active',
                'issued_to_phone' => !empty($opts['phone']) ? Phone::canonical($opts['phone']) : null,
                'issued_to_name' => $opts['name'] ?? null,
                'expires_at' => $opts['expires_at'] ?? null,
                'created_by' => $merchant->id,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);
            $this->log($card, 'issue', $amount, 'إصدار بطاقة', $merchant->id);
            return $card;
        });
    }

    /** يستبدل مبلغاً من البطاقة. يعيد المبلغ المطبَّق فعلاً. */
    public function redeem(User $merchant, string $code, string $amount, ?string $saleUlid = null): array
    {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ الاستبدال يجب أن يكون موجباً');
        }

        return DB::transaction(function () use ($merchant, $code, $amount, $saleUlid) {
            $card = GiftCard::where('merchant_user_id', $merchant->id)
                ->where('code', $code)->lockForUpdate()->first();
            if (!$card) throw new RuntimeException('البطاقة غير موجودة');
            if ($card->status === 'void') throw new RuntimeException('البطاقة ملغاة');
            if ($card->expires_at && $card->expires_at->isPast()) {
                throw new RuntimeException('انتهت صلاحية البطاقة');
            }
            if (MoneyService::gt($amount, (string) $card->balance)) {
                throw new RuntimeException('رصيد البطاقة غير كافٍ (المتاح: ' . $card->balance . ')');
            }

            $card->balance = MoneyService::normalize(MoneyService::sub((string) $card->balance, $amount));
            if (!MoneyService::isPositive((string) $card->balance)) $card->status = 'depleted';
            $card->save();

            $this->log($card, 'redeem', '-' . $amount, 'استبدال في بيع', $merchant->id, $saleUlid);
            return ['applied' => $amount, 'balance' => (string) $card->balance, 'card' => $card->fresh()];
        });
    }

    public function topUp(User $merchant, string $code, string $amount): GiftCard
    {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) throw new InvalidArgumentException('المبلغ غير صحيح');

        return DB::transaction(function () use ($merchant, $code, $amount) {
            $card = GiftCard::where('merchant_user_id', $merchant->id)
                ->where('code', $code)->lockForUpdate()->first();
            if (!$card) throw new RuntimeException('البطاقة غير موجودة');
            if ($card->status === 'void') throw new RuntimeException('البطاقة ملغاة');
            $card->balance = MoneyService::normalize(MoneyService::add((string) $card->balance, $amount));
            $card->status = 'active';
            $card->save();
            $this->log($card, 'topup', $amount, 'شحن رصيد', $merchant->id);
            return $card->fresh();
        });
    }

    public function void(User $merchant, string $code): GiftCard
    {
        return DB::transaction(function () use ($merchant, $code) {
            $card = GiftCard::where('merchant_user_id', $merchant->id)
                ->where('code', $code)->lockForUpdate()->first();
            if (!$card) throw new RuntimeException('البطاقة غير موجودة');
            $prev = (string) $card->balance;
            $card->balance = '0';
            $card->status = 'void';
            $card->save();
            $this->log($card, 'void', '-' . $prev, 'إلغاء البطاقة', $merchant->id);
            return $card->fresh();
        });
    }

    public function findByCode(User $merchant, string $code): ?GiftCard
    {
        return GiftCard::where('merchant_user_id', $merchant->id)->where('code', $code)->first();
    }

    private function log(GiftCard $card, string $type, string $amount, string $note, int $by, ?string $saleUlid = null): void
    {
        GiftCardTransaction::create([
            'gift_card_id' => $card->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $card->balance,
            'sale_ulid' => $saleUlid,
            'note' => $note,
            'created_by' => $by,
        ]);
    }

    private function uniqueCode(int $merchantId): string
    {
        do {
            // GC-XXXXXXXX (سهل الإملاء، بلا أحرف ملتبسة)
            $code = 'GC-' . strtoupper(Str::of(Str::random(10))->replace(['0', 'O', 'I', 'l', '1'], 'A')->substr(0, 8));
        } while (GiftCard::where('merchant_user_id', $merchantId)->where('code', $code)->exists());
        return $code;
    }
}
