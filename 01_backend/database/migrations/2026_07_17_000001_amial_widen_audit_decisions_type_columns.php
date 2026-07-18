<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-FIX(AUDIT-ENUM): كانت subject_type/actor_type أعمدة enum بقيم قليلة
 * (user/transaction/wallet/merchant/session/pin) بينما الكود يكتب أكثر من
 * عشرين قيمة (pending_transfer, safe_payment, family_fund, donation…).
 * على MySQL بوضع strict يفشل الإدراج بـ«Data truncated for column
 * 'subject_type'» فينهار التحويل كاملاً (AuditService داخل معاملة التحويل).
 * الحل: تحويل العمودين إلى VARCHAR — يقبل كل القيم الحالية والمستقبلية.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `audit_decisions` MODIFY `subject_type` VARCHAR(40) NOT NULL DEFAULT 'user'");
        DB::statement("ALTER TABLE `audit_decisions` MODIFY `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system'");
    }

    public function down(): void
    {
        // لا نعود للـenum — قيم أوسع من تعريفه القديم موجودة الآن في الجدول
    }
};
