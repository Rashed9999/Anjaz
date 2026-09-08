<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MERCHANT-NUMBER-001 — **الفرادةُ تُحرَس في القاعدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان `merchant_number` مشتقّاً من `user->id`، ففرادتُه مضمونةٌ بالاشتقاق
 * وحدَه **ولا قيدَ في الجدول**. وقد صار عشوائيّاً، فالاشتقاقُ ذهب ولم
 * يبقَ ما يمنع رقمين متطابقين.
 *
 * **ورقمان متطابقان لتاجرين يعني أنّ زبوناً يدفع للخطأ منهما** — ورقمُ
 * التاجر عنوانُ دفعٍ لا معرّفٌ داخليّ.
 *
 * و«اقرأ ثمّ اكتب» في الشيفرة لا يكفي: عمليّتان متزامنتان تقرآن الرقمَ
 * نفسَه فتجدانه حرّاً. **والقيدُ الفريدُ وحدَه ذرّيّ** — وهو درسٌ دُفع
 * ثمنُه هنا مرّتين (`firstOrCreate` و`USER_WALLET_{id}`).
 *
 * **و`NULL` يتكرّر تحت القيد الفريد في MySQL** — فالمتاجرُ التي لا رقمَ
 * لها لا تتعارض، وهو المطلوب: القائمون لا يُمسّون.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchants') || ! Schema::hasColumn('merchants', 'merchant_number')) {
            return;
        }

        // الأرقامُ القديمة قد تكون نصيةً ومكرّرة (مثل AM-FISH-006). لا
        // نغيّرها داخل هجرة: الرقم بيانُ دخولٍ وعنوانُ دفع، وتخمين أي صف
        // يستحقه يبدّل وجهة مالٍ قائم. لكن كل رقم جديد يولّده النظام هو
        // ستة أرقام من 1 إلى 9، وهذه الصيغة وحدها هي التي يجب أن يحرسها
        // القيد الذرّي. العمود المولّد يبقي الإرث غير القابل للحكم NULL
        // تحت القيد الفريد، ويمنع أي رقم جديد مكرر بلا تعطيل النشرة.
        if (! Schema::hasColumn('merchants', 'merchant_number_unique_value')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `merchants`
                ADD COLUMN `merchant_number_unique_value` VARCHAR(6)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN CHAR_LENGTH(`merchant_number`) = 6
                            AND `merchant_number` NOT LIKE '%0%'
                            AND CAST(`merchant_number` AS UNSIGNED) BETWEEN 111111 AND 999999
                        THEN `merchant_number`
                        ELSE NULL
                    END
                ) STORED
            SQL);
        }

        Schema::table('merchants', function (Blueprint $table) {
            $table->unique('merchant_number_unique_value', 'merchants_merchant_number_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('merchants')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->dropUnique('merchants_merchant_number_unique');
            });

            if (Schema::hasColumn('merchants', 'merchant_number_unique_value')) {
                Schema::table('merchants', function (Blueprint $table) {
                    $table->dropColumn('merchant_number_unique_value');
                });
            }
        }
    }
};
