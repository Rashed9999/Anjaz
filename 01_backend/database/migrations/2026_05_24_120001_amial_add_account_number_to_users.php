<?php

/**
 * AMIAL-ACCOUNT-NUMBER-001 — رقم حساب من 8 أرقام (آخرها خانة تحقّق Luhn).
 *
 * يحلّ مشكلة الخصوصية: التحويل برقم الحساب بدل كشف رقم الهاتف.
 * يُولّد لكل المستخدمين الحاليين هنا، وللجدد عبر hook في موديل User.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'account_number')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('account_number', 12)->nullable()->unique()->after('phone');
            });
        }

        // توليد للمستخدمين الحاليين (بدون account_number)
        $used = DB::table('users')->whereNotNull('account_number')->pluck('account_number')->flip()->toArray();

        DB::table('users')->whereNull('account_number')->orderBy('id')->chunkById(500, function ($users) use (&$used) {
            foreach ($users as $u) {
                $num = $this->generateUnique($used);
                $used[$num] = true;
                DB::table('users')->where('id', $u->id)->update(['account_number' => $num]);
            }
        });
    }

    /** يولّد رقماً فريداً 8 خانات مع خانة تحقّق Luhn، يتجنّب الأنماط القبيحة. */
    private function generateUnique(array $used): string
    {
        do {
            $num = $this->generateOne();
        } while (isset($used[$num]) || $this->isUgly($num));
        return $num;
    }

    private function generateOne(): string
    {
        // 7 خانات بيانات (أولها 1-9) + خانة تحقّق Luhn
        $body = (string) random_int(1, 9);
        for ($i = 0; $i < 6; $i++) {
            $body .= (string) random_int(0, 9);
        }
        return $body . $this->luhnCheckDigit($body);
    }

    private function luhnCheckDigit(string $body): string
    {
        $sum = 0;
        $reverse = strrev($body);
        for ($i = 0; $i < strlen($reverse); $i++) {
            $d = (int) $reverse[$i];
            if ($i % 2 === 0) { // المواضع التي ستلي خانة التحقق تُضاعف
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
        }
        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function isUgly(string $num): bool
    {
        if (preg_match('/^(\d)\1{7}$/', $num)) return true;       // كله نفس الرقم
        if ($num === '12345678' || $num === '87654321') return true;
        return false;
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'account_number')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['account_number']);
                $table->dropColumn('account_number');
            });
        }
    }
};
