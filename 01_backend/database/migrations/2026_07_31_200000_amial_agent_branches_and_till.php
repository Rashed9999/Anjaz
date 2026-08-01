<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AGENT-PORTAL-001 — فروع الوكيل وخزنة النقد.
 *
 * **السياق:** الوكلاء في أميال شركات صرافة، لكلٍّ منها فروع، وموظّفوها
 * يعملون على **الكمبيوتر لا الهاتف**. فلا يكفيهم التطبيق.
 *
 * ══════════════════════════════════════════════════════════════
 * **القرار الأوّل: الفرع حسابُ وكيلٍ ابن، لا كيانٌ جديد.**
 *
 * `agent_profiles` فيه `parent_agent_id` أصلاً. والفرع يفعل ما يفعله
 * الوكيل تماماً — يودع ويسحب — ويحتاج ما يحتاجه: محفظةً ورصيداً وحدوداً
 * وتسويات وعمولة.
 *
 * فجدولُ فروعٍ مستقلّ يعني تكرار ذلك كلّه، ثمّ انحرافه: تُضاف ميزةٌ للوكلاء
 * ولا تصل الفروع، أو يُحسب حدٌّ للوكيل ولا يُحسب لفرعه. وهذا الجدول لا
 * يحمل إلّا ما لا يحمله الحساب: الاسم، والعنوان، ومديرُ الفرع، **وخزنة
 * النقد**.
 * ══════════════════════════════════════════════════════════════
 *
 * ══════════════════════════════════════════════════════════════
 * **القرار الثاني — وهو الأهمّ: النقد الورقيّ يُتتبَّع مستقلّاً.**
 *
 * الموجود `agent_float_logs` يتتبّع الرصيد **الإلكترونيّ**. والوكيل عنده
 * قيدان يتحرّكان في اتّجاهين متعاكسين:
 *
 *   • **إيداع** (العميل يسلّم نقداً): النقد في الدرج **يزيد**، والرصيد
 *     الإلكترونيّ **ينقص** — لأنّ الوكيل يمنح العميل رصيداً من رصيده.
 *   • **سحب** (الوكيل يسلّم نقداً): النقد **ينقص**، والرصيد الإلكترونيّ
 *     **يزيد**.
 *
 * وبتتبّع الرصيد الإلكترونيّ وحده يقبل النظام طلب سحبٍ **لا يستطيع الفرع
 * دفعه**: الرصيد سيزيد فيوافق، والدرج فارغ فيقف العميل أمام موظّفٍ محرَج.
 *
 * وهذا ليس عطلاً نادراً — هو الحالة الطبيعية آخر اليوم في فرعٍ نشط.
 * ══════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_branches', function (Blueprint $t) {
            $t->id();

            // الوكيل الأمّ (شركة الصرافة).
            $t->unsignedBigInteger('agent_user_id')->index();

            // حساب الفرع نفسه — وكيلٌ ابن يحمل المحفظة والحدود.
            $t->unsignedBigInteger('branch_user_id')->unique();

            $t->string('name', 120);
            $t->string('code', 24)->index();
            $t->string('city', 80)->nullable();
            $t->text('address')->nullable();
            $t->string('phone', 32)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();

            $t->unsignedBigInteger('manager_user_id')->nullable();
            $t->boolean('is_active')->default(true)->index();

            // ساعات العمل: فرعٌ مغلق لا يُوجَّه إليه عميلٌ من التطبيق.
            $t->json('working_hours')->nullable();

            $t->timestamps();

            $t->unique(['agent_user_id', 'code']);
        });

        Schema::create('agent_cash_tills', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->unique();

            // النقد الورقيّ الموجود فعلاً في الدرج.
            $t->decimal('cash_on_hand', 20, 4)->default(0);

            // حدٌّ أعلى للنقد: فوقه يجب توريده إلى الخزنة أو البنك.
            // فرعٌ يحتفظ بملايين نقداً خطرٌ أمنيّ لا ميزة سيولة.
            $t->decimal('max_cash_on_hand', 20, 4)->default(0);

            // حدٌّ أدنى للتنبيه: تحته لا يقبل الفرع طلبات سحبٍ كبيرة.
            $t->decimal('min_cash_alert', 20, 4)->default(0);

            $t->timestamp('last_counted_at')->nullable();
            $t->decimal('last_counted_amount', 20, 4)->nullable();
            $t->timestamps();
        });

        Schema::create('agent_cash_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();

            $t->enum('direction', ['in', 'out']);

            $t->enum('reason', [
                'customer_deposit',   // العميل سلّم نقداً — النقد يدخل
                'customer_withdraw',  // الفرع سلّم نقداً — النقد يخرج
                'treasury_in',        // توريد من الخزنة/البنك إلى الفرع
                'treasury_out',       // توريد من الفرع إلى الخزنة/البنك
                'count_adjustment',   // فرقُ جردٍ فعليّ
                'opening',            // رصيد افتتاحيّ
            ])->index();

            $t->decimal('amount', 20, 4);

            // الرصيد قبل وبعد — كسطور الدفتر تماماً، فيُراجَع التسلسل ولا
            // يُعاد بناؤه من مجموعٍ قد يكون هو نفسه الخطأ.
            $t->decimal('balance_before', 20, 4);
            $t->decimal('balance_after', 20, 4);

            $t->string('reference', 64)->nullable()->index();
            $t->unsignedBigInteger('actor_user_id')->index();
            $t->unsignedBigInteger('customer_user_id')->nullable()->index();
            $t->text('note')->nullable();

            // مُلحَقٌ لا يُعدَّل: حركة نقدٍ يمكن تحريرها بعد وقوعها تُبطل
            // الجرد كلّه.
            $t->timestamp('created_at')->useCurrent();

            $t->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_cash_movements');
        Schema::dropIfExists('agent_cash_tills');
        Schema::dropIfExists('agent_branches');
    }
};
