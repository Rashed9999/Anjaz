<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPS-CONSOLE-001 — توسيع subject_type في سجل التدقيق.
 *
 * اكتُشف باختبار متصفّح حقيقي: إنشاء تذكرة دعم كان يحاول تسجيل قرار تدقيق
 * بـ subject_type='support_ticket' غير الموجود في الـ enum، فيُقتطع ويفشل
 * الإدراج بصمت (non-blocking) — أي فقدان سجلات تدقيق. نضيف القيمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE audit_decisions
            MODIFY subject_type ENUM('user','transaction','wallet','merchant','session','pin','support_ticket')
            NOT NULL DEFAULT 'user'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE audit_decisions
            MODIFY subject_type ENUM('user','transaction','wallet','merchant','session','pin')
            NOT NULL DEFAULT 'user'
        ");
    }
};
