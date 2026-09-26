<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-LOYALTY-AT-PAYMENT-001 — **النقاطُ تُستبدَل داخل البيعة، لا بجانبها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الملاحظة بنصّها:** «نقاط الولاء ليس لها استخدام أثناء الدفع… يبدو
 * أنّها غير مكتملة الميزة».
 *
 * **وقِيس فإذا هي أسوأ من ناقصة — هي تحرق نقاطاً بلا مقابل:**
 * `LoyaltyService::redeem()` مبنيّةٌ وتعمل، وشاشةُ «برنامج الولاء» تناديها
 * ثمّ **تطبع للكاشير: «خصم بقيمة X ر.ي — طبّقه على الفاتورة»**.
 *
 *   · نُقصت النقاطُ من حساب العميل، وسُجّلت حركةُ استبدال.
 *   · والخصمُ يُطبَّق **بيد الكاشير في شاشةٍ أخرى** — إن تذكّر.
 *   · وإن نسِي، أو أُلغيت البيعة، أو أُغلق التطبيق: **النقاطُ ذهبت
 *     والعميلُ دفع كاملاً**، ولا شيءَ يربط الحركةَ ببيعة.
 *
 * فالعمودان هنا يجعلان الاستبدالَ **جزءاً من البيعة**: يقع في معاملتها
 * نفسِها — فتسقط البيعةُ فتعود النقاط، وتُحفظ فتُحفظ الحركةُ موسومةً
 * بمعرّف البيعة.
 *
 * **ولا يُخلط بـ`discount_amount`:** ذاك خصمُ الكاشير وله سقفٌ في منحة
 * الدور (`assertDiscountAllowed`)؛ وهذا **مالُ العميل يصرفه من رصيده**،
 * فحبسُه تحت سقف الكاشير يمنع صاحبَ النقاط من إنفاق ما كسبه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_sales', function (Blueprint $table) {
            $table->decimal('loyalty_points_redeemed', 12, 2)->default(0)
                ->after('discount_amount');
            $table->decimal('loyalty_discount', 20, 4)->default(0)
                ->after('loyalty_points_redeemed');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_sales', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points_redeemed', 'loyalty_discount']);
        });
    }
};
