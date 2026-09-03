<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MULTI-CURRENCY-003 — **البيعةُ تعرف عملتَها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * بُنيت المحافظُ الأربع، **ثمّ قِيس أنّ الدولارَ لا يدخلها من بيعٍ أصلاً**:
 * `merchant_sales` بلا عمود عملةٍ إطلاقاً، ومسارا القبض يناديان
 * `assertReceiveAllowed` بلا عملة. فالمحافظُ مبنيّةٌ ولا يُوصَل إليها.
 *
 * **وثلاثةُ أعمدةٍ لا واحد، والثالثُ هو المهمّ:**
 *
 * ① `currency`         — بأيّ عملةٍ بيعت.
 * ② `fx_rate_to_base`  — **السعرُ لحظةَ البيع، منسوخاً لا مُشاراً إليه.**
 * ③ `base_amount`      — المكافئُ بالريال، محسوباً مرّةً ومحفوظاً.
 *
 * **ولمَ يُحفَظ المكافئُ ولا يُحسَب عند القراءة:** سعرُ الصرف يتحرّك.
 * فتقريرُ مبيعاتِ الشهر الماضي، لو حُسب بسعر اليوم، **يتغيّر كلَّ يوم**
 * — ويُطلَب من الماليّة أن تطابقه فلا تستطيع. وهو نمطُ العطل الذي كلّف
 * هذا المشروعَ سطرَ مكافئٍ على الإيصال يتغيّر بأثرٍ رجعيّ.
 *
 * **وعليه يُجمَع `base_amount` في كلّ تقريرٍ وحدّ** — فمجموعُ
 * `total_amount` عبر عملاتٍ مختلفةٍ ليس مبلغاً من شيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('merchant_sales') || Schema::hasColumn('merchant_sales', 'currency')) {
            return;
        }

        Schema::table('merchant_sales', function (Blueprint $table) {
            $table->string('currency', 3)->default('YER')->after('total_amount')->index();
            $table->decimal('fx_rate_to_base', 24, 8)->default(1)->after('currency');
            $table->decimal('base_amount', 24, 4)->nullable()->after('fx_rate_to_base');
        });

        // كلُّ ما بيع قبل اليومَ بالريال — يُقال صراحةً ولا يُترَك للافتراضيّ،
        // و`base_amount` يساوي الإجماليَّ نفسَه بسعر ١.
        DB::statement("UPDATE merchant_sales SET currency = 'YER', fx_rate_to_base = 1, base_amount = total_amount");
    }

    public function down(): void
    {
        if (Schema::hasTable('merchant_sales') && Schema::hasColumn('merchant_sales', 'currency')) {
            Schema::table('merchant_sales', function (Blueprint $table) {
                $table->dropColumn(['currency', 'fx_rate_to_base', 'base_amount']);
            });
        }
    }
};
