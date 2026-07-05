<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-INSIDER-001 — دفاعات التهديد الداخلي (احتيال/تجاوز الموظفين).
 *
 * 1) approval_requests — Maker-Checker: الإجراءات الحساسة تتطلب موافقة
 *    موظف ثانٍ مختلف (أربع عيون) قبل التنفيذ.
 * 2) security_alerts — تنبيهات الشذوذ السلوكي للموظفين (إفراط اطّلاع،
 *    عمل خارج الدوام، أنماط مشبوهة) لمسؤول الأمن.
 * 3) سلسلة تجزئة لسجل التدقيق — prev_hash/entry_hash على audit_decisions
 *    + رأس السلسلة audit_chain_head: أي حذف/تعديل يكسر السلسلة ويُكشف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 20)->unique();       // APR-000001
            $table->string('action_type', 40)->index();
            // unfreeze_wallet | reset_pin | (قابلة للتوسّع: adjust_limit, fee_change ...)
            $table->unsignedBigInteger('subject_user_id')->index(); // العميل المتأثر
            $table->unsignedBigInteger('maker_admin_id')->index();  // مقدّم الطلب
            $table->unsignedBigInteger('checker_admin_id')->nullable(); // المعتمِد/الرافض
            $table->text('reason');                                  // سبب المُقدِّم (إلزامي)
            $table->text('checker_note')->nullable();                // ملاحظة المعتمِد
            $table->json('payload')->nullable();                     // تفاصيل إضافية للتنفيذ
            $table->string('status', 20)->default('pending')->index();
            // pending | approved | rejected | expired | failed
            $table->timestamp('expires_at')->nullable();             // صلاحية الطلب
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->index();       // الموظف موضوع التنبيه
            $table->string('alert_type', 50)->index();
            // excessive_profile_views | after_hours_access | excessive_searches | rapid_sequential_access
            $table->string('severity', 10)->default('warning');    // info|warning|critical
            $table->json('details')->nullable();                    // عدّادات/عتبات
            $table->string('status', 20)->default('new')->index(); // new|acknowledged
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->date('alert_date');                             // لمنع تكرار تنبيه اليوم نفسه
            $table->timestamps();

            // تنبيه واحد لكل موظف/نوع/يوم (منع الإغراق)
            $table->unique(['admin_id', 'alert_type', 'alert_date']);
        });

        // سلسلة التجزئة على سجل التدقيق
        Schema::table('audit_decisions', function (Blueprint $table) {
            $table->char('prev_hash', 64)->nullable()->after('severity');
            $table->char('entry_hash', 64)->nullable()->after('prev_hash')->index();
        });

        Schema::create('audit_chain_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();          // صف واحد id=1
            $table->char('last_hash', 64);
            $table->unsignedBigInteger('last_audit_id')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        // بذرة السلسلة (genesis)
        DB::table('audit_chain_head')->insert([
            'id' => 1,
            'last_hash' => hash('sha256', 'AMIAL-AUDIT-CHAIN-GENESIS'),
            'last_audit_id' => 0,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');
        Schema::table('audit_decisions', function (Blueprint $table) {
            $table->dropColumn(['prev_hash', 'entry_hash']);
        });
        Schema::dropIfExists('security_alerts');
        Schema::dropIfExists('approval_requests');
    }
};
