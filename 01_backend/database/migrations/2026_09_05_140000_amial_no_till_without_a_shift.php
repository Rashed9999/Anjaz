<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SHIFT-GATE-001 — **لا شبّاكَ بلا ورديّة، ولا فاتورةَ بلا اسم.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **بنصّ صاحب المشروع:** «لا يتمّ فتح الكاشير حتّى لو كان مالك المتجر إلّا
 * بفتح ورديّة تحمل اسمَه ووقتَ عمله اليوميّ والشهريّ، وعند الإقفال يجب
 * مطابقةُ ما فيه دفعٌ نقديّ ومعرفةُ الفائض والناقص… والفاتورةُ يجب أن
 * تحمل اسمَ فاتح الورديّة أسفلها».
 *
 * **وقِيس قبل البناء، فالحالُ أسوأُ ممّا يُظنّ:**
 *
 *   · `CashierService` — **صفرُ ذكرٍ للورديّة**. البيعُ يقع والدرجُ
 *     مفتوحٌ بلا أحد، ولا شيءَ يمنع.
 *   · `merchant_sales` — **لا `shift_id`**. فلا تُنسَب بيعةٌ إلى ورديّة،
 *     ولا يُعرف من كان على الشبّاك بعد ساعة.
 *   · `cashier_shifts.opened_by` رقمٌ وحدَه — **ولا اسم**.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **① لماذا يُخزَّن الاسمُ لقطةً ولا يُقرأ من المستخدم؟**
 *
 * الموظّفُ يُعاد تسميتُه، أو يُحذَف، أو يُنقَل. وقراءةُ الاسم اليومَ من
 * جدول المستخدمين **تُعيد كتابةَ فواتيرِ الشهر الماضي**: فاتورةٌ طُبعت
 * باسم «أحمد» تُقرأ غداً باسم «محمد» — والورقةُ في يد الزبون تقول
 * الأولى. (والقاعدةُ نفسُها التي تحكم `unit_cost` في حركة المخزون:
 * تُلتقَط لحظةَ الحدث.)
 *
 * **② ولماذا `shift_id` على البيعة لا على الورديّة فقط؟**
 *
 * الورديّةُ تعرف مداها بالزمن (`opened_at`…`closed_at`)، والحسابُ بالزمن
 * يعمل **حتّى يقع تداخل**: ورديّةٌ نُسي إقفالُها ثمّ فُتحت أخرى، أو
 * ساعةٌ تُعدَّل. فالرابطُ الصريحُ يقول من قبض هذه البيعةَ بعينها ولا
 * يُستنتَج. (القاعدة السادسة: يُحسب من مصدره — والمصدرُ هنا الرابط.)
 *
 * **③ و`null` تعني «بيعةٌ قبل هذا الحارس» لا «بلا ورديّة»** (السابعة):
 * ما بيع قبل اليوم لا ورديّةَ له أصلاً، وتصفيرُه يخترع نسبةً لم تكن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            // ① لقطةُ الاسم — لا يتغيّر بتغيّر الموظّف.
            $table->string('opened_by_name', 190)->nullable()->after('opened_by');
            $table->unsignedBigInteger('closed_by')->nullable()->after('opened_by_name');
            $table->string('closed_by_name', 190)->nullable()->after('closed_by');

            // **ودورُ الفاتح يُقال**: مالكٌ أم موظّفُ نقطة بيع. فورديّةُ
            // المالك تُقرأ في التقرير باسمها لا «موظّف غير معروف».
            $table->string('opened_by_role', 24)->nullable()->after('closed_by_name');
        });

        // ② الرابطُ الصريح — على كلّ جدولِ بيعٍ يقبض نقداً في هذه الورديّة.
        foreach (['merchant_sales', 'pharmacy_sales'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'shift_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('shift_id')->nullable()->index();
            });
        }

        // ③ **والحارسُ يُطفأ من اللوحة لا من الشيفرة.**
        //
        // مطلبُ صاحب المشروع أن يكون مُلزِماً — فالافتراضيُّ `true`.
        // لكنّ تاجراً واحداً يعمل وحدَه في بسطةٍ قد لا يريد خطوةً قبل
        // كلّ بيعة، **والقرارُ التجاريُّ ليس قرارَ شيفرة**. فيبقى الحدُّ
        // قائماً ويُرفَع بقرارٍ مكتوبٍ يُرى، لا بتعليقِ سطرٍ في الخادم.
        Schema::table('merchant_profiles', function (Blueprint $table) {
            $table->boolean('require_shift_to_sell')->default(true)
                ->after('business_type');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_profiles', function (Blueprint $table) {
            $table->dropColumn('require_shift_to_sell');
        });

        foreach (['merchant_sales', 'pharmacy_sales'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'shift_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('shift_id'));
            }
        }

        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'opened_by_name', 'closed_by', 'closed_by_name', 'opened_by_role',
            ]);
        });
    }
};
