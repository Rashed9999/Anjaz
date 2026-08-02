<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SHIFT-STATEMENT-001 — تمييزُ حركة **الدرج** من حركة **الخزنة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * توريدُ نقدٍ من الخزنة إلى الدرج يُنتج سطرين في السجلّ نفسه: خروجٌ من
 * الخزنة ودخولٌ إلى الدرج. وتوريدُه في الاتّجاه المعاكس يُنتج سطرين
 * بالأسباب نفسها والاتّجاهين نفسيهما — **معكوسَي النسبة**.
 *
 * فالسطر `(out, treasury_out)` قد يكون خزنةً (توريدٌ إلى الدرج) وقد يكون
 * درجاً (تسليمٌ إلى الخزنة). ولا يفرّق بينهما شيءٌ في الجدول.
 *
 * وكشفُ تسوية الورديّة يحتاج حركة **الدرج وحدها** — فبلا هذا التمييز
 * يُحسب توريدُ الخزنة ضمن ما بيد الصرّاف، فيُنسب إليه مالٌ لم يمرّ بدرجه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقيمة `null` مقصودة ولا تُملأ بتخمين.**
 *
 * صفوف `treasury_*` القديمة لا يمكن نسبتها إلى درجٍ أو خزنةٍ بيقين: كلاهما
 * يحمل نفس السبب ونفس الورديّة. فتُترك «غير معروفة» ويقول الكشف ذلك
 * صراحةً — لأنّ تخميناً هنا يُنتج رقماً يبدو صحيحاً وهو مختَلق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_cash_movements', function (Blueprint $table) {
            $table->boolean('is_drawer')->nullable()->after('shift_id');
            $table->index(['shift_id', 'is_drawer'], 'agent_cash_mv_shift_drawer_idx');
        });

        // ما يُنسب بيقين:
        //   • إيداع العميل وسحبه داخل ورديّة ⇒ درج (النقد في يد الصرّاف)
        //   • تسوية الجرد ⇒ درج (هي فرقُ عدّ الدرج)
        //   • فتح الورديّة وإغلاقها والرصيد الافتتاحيّ ⇒ خزنة
        DB::table('agent_cash_movements')
            ->whereNotNull('shift_id')
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw', 'count_adjustment'])
            ->update(['is_drawer' => true]);

        DB::table('agent_cash_movements')
            ->whereIn('reason', ['shift_open', 'shift_close', 'opening'])
            ->update(['is_drawer' => false]);

        // حركةٌ بلا ورديّة لا تكون درجاً أصلاً — لا درج بلا ورديّة مفتوحة.
        DB::table('agent_cash_movements')
            ->whereNull('shift_id')
            ->update(['is_drawer' => false]);
    }

    public function down(): void
    {
        Schema::table('agent_cash_movements', function (Blueprint $table) {
            $table->dropIndex('agent_cash_mv_shift_drawer_idx');
            $table->dropColumn('is_drawer');
        });
    }
};
