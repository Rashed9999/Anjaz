<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CASH-TENDERED-001 — **المبلغُ المستلم، والباقي يُحسب لا يُذكَر ذهناً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **من أين جاءت:** أرسل صاحبُ المشروع شاشاتِ تطبيقٍ محاسبيٍّ منافس، وفي
 * ذيل فاتورته سطران: «المبلغ المستلم» و«صافي الفاتوره». وقِيس فليس في
 * أميال حقلٌ للمستلم ولا للباقي إطلاقاً — **والكاشيرُ يحسبه في رأسه في
 * كلّ بيعةٍ نقديّة**، والزبونُ واقفٌ ينتظر.
 *
 * **ولمَ يُخزَّن ولا يُترَك حسابَ شاشة:** الباقي رقمٌ يُقال للزبون ويُطبع
 * على ورقته. فإن اختلف بعد ساعةٍ على درهم لم يبقَ ما يُراجَع — لا في
 * الإيصال ولا في الجرد. **ورقمٌ قيل ولم يُحفظ لا يُدافَع عنه.**
 *
 * **و`null` تعني «لم يُدخَل» لا صفراً** (القاعدة السابعة): بيعةُ الآجل
 * وبيعةُ المحفظة لا مستلَمَ فيهما أصلاً، وصفرٌ هناك يُقرأ «استُلم صفر».
 *
 * **ولا يُخزَّن الباقي** — يُحسب `المستلم − الإجمالي` من مصدره، وعمودٌ
 * مخزَّنٌ لهما ثالثٌ يمكن أن يناقضهما (القاعدة السادسة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_sales', function (Blueprint $table) {
            $table->decimal('amount_received', 20, 4)->nullable()
                ->after('loyalty_discount');
        });

        // **وشبّاكُ الصيدليّة يبيع نقداً كالكاشير** — وجدولُه مستقلٌّ
        // (`pharmacy_sales`). فوضعُ الحقل في واحدٍ دون الآخر يجعل نصفَ
        // البائعين يرى الباقيَ على الإيصال ونصفَهم لا يراه، **والفرقُ
        // يتبع القطاعَ لا قرارَ أحد**. (القاعدة الرابعة: مدخلان.)
        if (Schema::hasTable('pharmacy_sales')) {
            Schema::table('pharmacy_sales', function (Blueprint $table) {
                $table->decimal('amount_received', 20, 4)->nullable()
                    ->after('total_amount');
            });
        }
    }

    public function down(): void
    {
        Schema::table('merchant_sales', function (Blueprint $table) {
            $table->dropColumn('amount_received');
        });

        if (Schema::hasTable('pharmacy_sales')) {
            Schema::table('pharmacy_sales', function (Blueprint $table) {
                $table->dropColumn('amount_received');
            });
        }
    }
};
