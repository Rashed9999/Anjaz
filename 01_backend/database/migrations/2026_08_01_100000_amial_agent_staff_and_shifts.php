<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AGENT-STAFF-001 — موظّفو شركة الصرافة وورديّاتهم.
 *
 * **الخطأ الذي تُصلحه هذه الهجرة.** بُنيت البوّابة على حسابٍ واحدٍ للوكيل
 * يختار الفرع من قائمةٍ منسدلة قبل كلّ عملية. وهذا تصميمُ محلٍّ واحد، لا
 * تصميمُ شركة صرافةٍ مركزها الشحر ولها آلاف الفروع وآلاف الموظّفين، كلّ
 * موظّفٍ على كمبيوترٍ في فرعه.
 *
 * والقاعدة الصحيحة: **الهويّة تحدّد النطاق، لا القائمة المنسدلة.** الصرّاف
 * حين يدخل يكون فرعه معروفاً لأنّه فرعُه هو — فلا يُسأل عنه، ولا يستطيع
 * اختيار غيره. والقائمة المنسدلة لم تكن إزعاجاً في الواجهة فحسب: كانت
 * تعني أنّ موظّفاً واحداً يملك مفاتيح كلّ الفروع.
 *
 * ── لماذا الموظّف ليس `User` ─────────────────────────────────────────────
 *
 * الصرّاف لا يملك محفظةً إلكترونيّة. الرصيد الإلكترونيّ سيولةُ **الفرع**،
 * والصرّاف يتصرّف نيابةً عنه. ولو جُعل كلّ صرّافٍ مستخدماً لأُنشئت له محفظةٌ
 * يجب ألّا يملكها، ولدخل آلافُ الموظّفين في طوابير التوثيق ومكافحة غسل
 * الأموال بلا معنى، ولاختلط عدّ الوكلاء بعدّ موظّفيهم.
 *
 * ── لماذا للصرّاف درجٌ منفصلٌ عن خزنة الفرع ──────────────────────────────
 *
 * كانت الخزنة على مستوى الفرع. وفرعٌ فيه عشرون شبّاكاً وخزنةٌ واحدة يعني
 * أنّ عجزاً قدره خمسة آلاف لا يدلّ على أحد — يُقسَّم على عشرين بريئاً أو
 * يُنسى. وشركات الصرافة تعمل بخزنة فرعٍ يأخذ منها الصرّاف **عهدةً** أوّل
 * ورديّته ويردّها آخرها. فالدرج هو وحدة المسؤولية، والخزنة وحدة السيولة.
 *
 * وأثرٌ ثانٍ أهمّ: قدرةُ الدفع تُقاس بالدرج لا بالخزنة. خزنةٌ فيها خمسة
 * ملايين لا تُعين صرّافاً في درجه عشرة آلاف — لا يستطيع أن يعدّ ما ليس
 * بيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── موظّفو شركة الصرافة ──────────────────────────────────────────
        if (!Schema::hasTable('agent_staff')) {
            Schema::create('agent_staff', function (Blueprint $t) {
                $t->id();

                // الشركة (المركز الرئيسيّ) — يبقى معروفاً حتى لو نُقل الموظّف
                // بين الفروع، وعليه تُبنى كلّ عزلة البيانات بين الشركات.
                $t->unsignedBigInteger('agent_user_id')->index();

                // الفرع الذي يعمل فيه. مديرُ الشركة نفسه بلا فرع.
                $t->unsignedBigInteger('branch_id')->nullable()->index();

                // اسم الدخول: رمزٌ قصيرٌ يحفظه الموظّف — `MKL-014` لا بريدٌ
                // ولا هاتف. والهاتف يتغيّر ويُعاد استعماله بين الموظّفين،
                // فلا يصلح مفتاحَ دخولٍ في شركةٍ فيها آلاف.
                $t->string('username', 40)->unique();

                $t->string('name', 120);
                $t->string('phone', 20)->nullable();
                $t->string('password');

                // head_office = الإدارة العامّة، branch_manager = مدير فرع،
                // teller = صرّاف على الشبّاك.
                $t->string('role', 20)->default('teller')->index();

                $t->boolean('is_active')->default(true)->index();
                $t->timestamp('last_login_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();

                // حدٌّ لكلّ عمليةٍ يضعه مدير الشركة على الصرّاف. صفر = بلا حدّ
                // خاصّ (تُطبَّق حدود المنصّة وحدها).
                $t->decimal('max_txn_amount', 20, 4)->default(0);

                $t->timestamps();

                $t->index(['agent_user_id', 'branch_id']);
            });
        }

        // ── ورديّات الصرّافين ────────────────────────────────────────────
        if (!Schema::hasTable('agent_shifts')) {
            Schema::create('agent_shifts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('staff_id')->index();

                // العهدة الافتتاحيّة — تخرج من خزنة الفرع إلى درج الصرّاف.
                $t->decimal('opening_float', 20, 4)->default(0);

                // نقدُ الدرج الآن. يزيد بالإيداع وينقص بالسحب.
                $t->decimal('cash_on_hand', 20, 4)->default(0);

                $t->decimal('deposits_total', 20, 4)->default(0);
                $t->decimal('withdrawals_total', 20, 4)->default(0);
                $t->unsignedInteger('deposits_count')->default(0);
                $t->unsignedInteger('withdrawals_count')->default(0);

                // ما عُدّ باليد عند الإغلاق، والفرق بينه وبين المتوقَّع.
                // يبقيان فارغين ما دامت الورديّة مفتوحة — «لم يُعدّ» ليس صفراً.
                $t->decimal('counted_cash', 20, 4)->nullable();
                $t->decimal('variance', 20, 4)->nullable();
                $t->text('close_note')->nullable();

                $t->string('status', 12)->default('open')->index();
                $t->timestamp('opened_at')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->unsignedBigInteger('closed_by')->nullable();

                $t->timestamps();

                $t->index(['branch_id', 'status']);
                $t->index(['staff_id', 'status']);
            });
        }

        // ── نسبةُ الحركة إلى موظّفها وورديّته ────────────────────────────
        //
        // بدونهما تبقى الحركة منسوبةً إلى الفرع وحده، فيُعرف أنّ الفرع نقص
        // ولا يُعرف أيّ شبّاك — وهو بالضبط ما يجعل الجرد بلا أثر.
        Schema::table('agent_cash_movements', function (Blueprint $t) {
            if (!Schema::hasColumn('agent_cash_movements', 'shift_id')) {
                $t->unsignedBigInteger('shift_id')->nullable()->after('branch_id')->index();
            }
            if (!Schema::hasColumn('agent_cash_movements', 'staff_id')) {
                $t->unsignedBigInteger('staff_id')->nullable()->after('shift_id')->index();
            }
        });

        // ── سببان جديدان: عهدة الورديّة وتسليمها ─────────────────────────
        //
        // العمود `enum` ويبقى كذلك عمداً. القيد على مستوى قاعدة البيانات هو
        // ما جعل أوّل تشغيلٍ لهذه الخدمة يسقط فوراً على سببٍ لم يُسجَّل، بدل
        // أن يُكتب سببٌ مجهولٌ في السجلّ ويُكتشف يوم يُسأل عنه محقّق.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `agent_cash_movements` MODIFY `reason` ENUM("
                . "'customer_deposit','customer_withdraw','treasury_in','treasury_out',"
                . "'count_adjustment','opening','shift_open','shift_close'"
                . ") NOT NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::table('agent_cash_movements', function (Blueprint $t) {
            foreach (['shift_id', 'staff_id'] as $col) {
                if (Schema::hasColumn('agent_cash_movements', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('agent_shifts');
        Schema::dropIfExists('agent_staff');
    }
};
