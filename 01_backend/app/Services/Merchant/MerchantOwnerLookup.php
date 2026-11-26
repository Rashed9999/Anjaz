<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use App\Models\User;
use App\Services\EncryptionService;
use App\Support\Access\AccessConstants as A;
use App\Support\Phone;
use Illuminate\Support\Facades\Schema;

/**
 * مصدر الحقيقة الوحيد للبحث عن مالك المنشأة (القدامى والجدد) في أميال باي:
 *  - يقبل السجلات التاريخية من 6cash حيث type = MERCHANT_TYPE و role بقي '0' أو 'user' أو '' أو null
 *    بسبب قيد ترحيل 2026_06_02، بينما يرفض صراحةً أي دور آخر (مثل pos أو admin أو agent).
 *  - يطابق رقم التاجر والبريد بلا حساسية لحالة الأحرف، ويطابق كل صيغ الهاتف + phone_canonical + blind_index.
 *  - لا يتجاوز التحقق من كلمة المرور ولا توثيق المنشأة (KYC) ولا حالة النشاط.
 */
final class MerchantOwnerLookup
{
    /** الأدوار التاريخية المقبولة لحساب يحمل type = MERCHANT_TYPE. */
    private const ELIGIBLE_ROLES = [
        A::ROLE_MERCHANT,
        A::ROLE_USER,
        '0',
        '',
    ];

    public static function isEligibleOwnerRole(?string $role): bool
    {
        if ($role === null) {
            return true;
        }

        return in_array(mb_strtolower(trim($role)), self::ELIGIBLE_ROLES, true);
    }

    public static function findByIdentifier(string $identifier): ?User
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }

        $lower = mb_strtolower($trimmed);
        $merchantId = Merchant::query()
            ->whereRaw('LOWER(TRIM(merchant_number)) = ?', [$lower])
            ->value('user_id');

        $isPhoneLike = preg_match('/^[+0-9\s\-()]+$/', $trimmed) === 1;
        $phones = $isPhoneLike ? Phone::variants($trimmed) : [];
        $phoneCanonical = $isPhoneLike ? Phone::canonical($trimmed) : '';

        $encryption = null;
        try {
            $encryption = app(EncryptionService::class);
        } catch (\Throwable) {
            $encryption = null;
        }

        $phoneBlindIndexes = [];
        if ($encryption !== null && $phones !== [] && Schema::hasColumn('users', 'phone_blind_index')) {
            foreach ($phones as $variant) {
                $idx = $encryption->blindIndex($variant, 'phone');
                if ($idx !== null && $idx !== '') {
                    $phoneBlindIndexes[] = $idx;
                }
            }
            $phoneBlindIndexes = array_values(array_unique($phoneBlindIndexes));
        }

        $emailBlindIndex = null;
        if ($encryption !== null && str_contains($trimmed, '@') && Schema::hasColumn('users', 'email_blind_index')) {
            $emailBlindIndex = $encryption->blindIndex($lower, 'email');
        }

        $hasEmailCanonical = Schema::hasColumn('users', 'email_canonical');
        $hasPhoneCanonical = Schema::hasColumn('users', 'phone_canonical');

        return User::query()
            ->where('type', MERCHANT_TYPE)
            ->where(function ($roleQuery) {
                $roleQuery->whereNull('role')
                    ->orWhereIn('role', self::ELIGIBLE_ROLES);
            })
            ->where(function ($query) use (
                $lower,
                $merchantId,
                $phones,
                $phoneCanonical,
                $phoneBlindIndexes,
                $emailBlindIndex,
                $hasEmailCanonical,
                $hasPhoneCanonical,
            ) {
                $query->whereRaw('LOWER(TRIM(email)) = ?', [$lower]);
                if ($hasEmailCanonical) {
                    $query->orWhere('email_canonical', $lower);
                }
                if ($emailBlindIndex !== null && $emailBlindIndex !== '') {
                    $query->orWhere('email_blind_index', $emailBlindIndex);
                }
                if ($phones !== []) {
                    $query->orWhereIn('phone', $phones);
                }
                if ($hasPhoneCanonical && $phoneCanonical !== '') {
                    $query->orWhere('phone_canonical', $phoneCanonical);
                }
                if ($phoneBlindIndexes !== []) {
                    $query->orWhereIn('phone_blind_index', $phoneBlindIndexes);
                }
                if ($merchantId) {
                    $query->orWhere('id', $merchantId);
                }
            })
            ->first();
    }
}
