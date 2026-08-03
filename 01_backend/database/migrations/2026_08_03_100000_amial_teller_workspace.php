<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-TELLER-WS-001 — مساحةُ عمل الصرّاف: ما تحتاجه من جداول.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **شاشةُ الشبّاك ليست شاشةَ إيداعٍ وسحب — هي نقطةُ التقاء أنظمةٍ عشرة.**
 *
 * والفرق بينهما ليس تجميلاً: صرّافٌ لا يرى حدَّه المتبقّي يكتشفه بالرفض
 * أمام العميل، وصرّافٌ لا يرى صلاحياته يحاول ما لا يملك فيقف، وصرّافٌ
 * يتجاوز حدّه لا يجد إلّا الرفض — بينما الصحيح أن يطلب موافقة مديره.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثة جداول، ولكلٍّ سببُه:**
 *
 * ١. `agent_teller_requests` — طلبُ موافقةٍ من الصرّاف إلى مديره.
 *
 *    ولم يُستعمل `approval_requests` القائم لأنّه مبنيٌّ على
 *    `maker_admin_id`/`checker_admin_id`: مُوظّفو شركات الصرافة **ليسوا
 *    مستخدمين** ولا مشرفين — هم صفوفٌ في `agent_staff`. وحشرُهم في عمودٍ
 *    يعني معرّفاً يشير إلى جدولٍ آخر، وهو فسادٌ صامت يظهر بعد شهور حين
 *    يُقرأ الطلب باسم مشرفٍ لا وجود له.
 *
 * ٢. `agent_announcements` — تعاميم الإدارة إلى الشبابيك.
 *
 *    ولها مستويان: تعميمٌ من أميال إلى الجميع (`agent_user_id = null`)،
 *    وتعميمٌ من شركة صرافةٍ إلى موظّفيها. ومن غير هذا الفصل تصير رسالةُ
 *    شركةٍ لصرّافيها مقروءةً في شركةٍ منافسة.
 *
 * ٣. `agent_panic_alerts` — زرّ الطوارئ.
 *
 *    **ولا يُحذف ولا يُعدَّل.** بلاغُ إكراهٍ يُلغيه صاحبه تحت التهديد
 *    يعني أنّ الزرّ لا يحمي أحداً. فالإلغاء يُسجَّل حالةً جديدة ولا يمحو
 *    الصفّ، والوقت والفرع والموظّف تُختم لحظةَ الضغط.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── حدود الصرّاف: ما لم يكن في `agent_staff` ────────────────────
        //
        // كان فيه `max_txn_amount` وحده — أي حدُّ العمليّة الواحدة. وهو لا
        // يمنع من يقسّم مليوناً إلى عشرين عمليّة. فالحدّ اليوميّ وعددُ
        // العمليّات هما ما يُوقف التقسيم.
        Schema::table('agent_staff', function (Blueprint $table) {
            $table->decimal('daily_limit', 20, 4)->default(0)->after('max_txn_amount');
            $table->unsignedInteger('daily_count_limit')->default(0)->after('daily_limit');

            // صلاحياتٌ صريحة. و`null` تعني «الافتراضيّ حسب الدور» —
            // وهي ليست «لا صلاحية»: «غير محدَّد» ليس «ممنوع».
            $table->json('capabilities')->nullable()->after('daily_count_limit');
        });

        Schema::create('agent_teller_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 24)->unique();
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('staff_id');            // الطالب
            $table->unsignedBigInteger('shift_id')->nullable();

            $table->string('kind', 32);                        // over_limit | restricted_op
            $table->string('operation', 32);                   // deposit | withdraw | …
            $table->decimal('amount', 20, 4)->default(0);
            $table->unsignedBigInteger('customer_user_id')->nullable();
            $table->text('reason');

            // ما مُنع فعلاً — يُحفظ لحظةَ الطلب لا يُحسب عند القراءة.
            // فالحدّ يتغيّر، وقارئُ الطلب بعد شهرٍ يجب أن يرى ما وقع.
            $table->json('limit_snapshot')->nullable();

            $table->string('status', 20)->default('pending');  // pending|approved|rejected|expired|used
            $table->unsignedBigInteger('decided_by_staff_id')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['agent_user_id', 'status'], 'atr_agent_status_idx');
            $table->index(['branch_id', 'status'], 'atr_branch_status_idx');
            $table->index(['staff_id', 'created_at'], 'atr_staff_time_idx');
        });

        Schema::create('agent_announcements', function (Blueprint $table) {
            $table->id();
            // null = من أميال إلى كلّ الشبابيك.
            $table->unsignedBigInteger('agent_user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('audience', 20)->default('all');    // all|tellers|managers
            $table->string('severity', 12)->default('info');   // info|warning|critical
            $table->string('title', 160);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at'], 'aan_live_idx');
            $table->index('agent_user_id', 'aan_agent_idx');
        });

        Schema::create('agent_panic_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('alert_number', 24)->unique();
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('shift_id')->nullable();

            $table->string('kind', 24)->default('duress');     // duress|robbery|fraud|threat
            $table->text('note')->nullable();

            // الموقع اختياريّ **ويُقال إن غاب**: المتصفّح يمنع تحديد
            // الموقع على اتّصالٍ غير مشفَّر، فغيابُه ليس إخفاءً من الموظّف.
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('geo_state', 24)->default('unavailable'); // ok|denied|unavailable|insecure

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->string('status', 20)->default('open');     // open|acknowledged|resolved|false_alarm
            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'apa_status_idx');
            $table->index(['agent_user_id', 'created_at'], 'apa_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_panic_alerts');
        Schema::dropIfExists('agent_announcements');
        Schema::dropIfExists('agent_teller_requests');

        Schema::table('agent_staff', function (Blueprint $table) {
            $table->dropColumn(['daily_limit', 'daily_count_limit', 'capabilities']);
        });
    }
};
