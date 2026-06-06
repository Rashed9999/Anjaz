<?php

namespace App\Services;

use App\Models\User;

/**
 * AMIAL-ACCOUNT-NUMBER-001 — توليد/تحقّق/حلّ رقم الحساب (8 أرقام + Luhn).
 */
class AccountNumberService
{
    /** يولّد رقم حساب فريداً ويعيّنه للمستخدم إن لم يكن لديه. */
    public function assign(User $user): string
    {
        if (!empty($user->account_number)) {
            return $user->account_number;
        }
        $num = $this->generateUnique();
        $user->account_number = $num;
        $user->save();
        return $num;
    }

    public function generateUnique(int $maxTries = 50): string
    {
        for ($i = 0; $i < $maxTries; $i++) {
            $num = $this->generateOne();
            if ($this->isUgly($num)) continue;
            if (!User::where('account_number', $num)->exists()) {
                return $num;
            }
        }
        throw new \RuntimeException('تعذّر توليد رقم حساب فريد — مساحة الأرقام شبه ممتلئة');
    }

    public function generateOne(): string
    {
        $body = (string) random_int(1, 9);
        for ($i = 0; $i < 6; $i++) {
            $body .= (string) random_int(0, 9);
        }
        return $body . $this->luhnCheckDigit($body);
    }

    /** يتحقق من صحة رقم الحساب (8 خانات + Luhn سليم). */
    public function isValid(string $number): bool
    {
        $number = trim($number);
        if (!preg_match('/^\d{8}$/', $number)) return false;
        return $this->luhnValid($number);
    }

    /**
     * يحلّ المستلِم: رقم حساب (8 أرقام) أو رقم هاتف.
     * @param bool $allowPhone هل يُسمح بالهاتف (التحويل بين العملاء + الوكيل = نعم).
     */
    public function resolveRecipient(string $identifier, bool $allowPhone = true): ?User
    {
        $identifier = trim($identifier);

        // رقم حساب صالح؟
        if (preg_match('/^\d{8}$/', $identifier) && $this->luhnValid($identifier)) {
            $byAccount = User::where('account_number', $identifier)->first();
            if ($byAccount) return $byAccount;
        }

        // هاتف
        if ($allowPhone) {
            return User::where('phone', $identifier)->first();
        }
        return null;
    }

    // ---- Luhn ----

    public function luhnCheckDigit(string $body): string
    {
        $sum = 0;
        $reverse = strrev($body);
        for ($i = 0; $i < strlen($reverse); $i++) {
            $d = (int) $reverse[$i];
            if ($i % 2 === 0) {
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
        }
        return (string) ((10 - ($sum % 10)) % 10);
    }

    public function luhnValid(string $number): bool
    {
        $sum = 0;
        $reverse = strrev($number);
        for ($i = 0; $i < strlen($reverse); $i++) {
            $d = (int) $reverse[$i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }

    private function isUgly(string $num): bool
    {
        if (preg_match('/^(\d)\1{7}$/', $num)) return true;
        if ($num === '12345678' || $num === '87654321') return true;
        return false;
    }
}
