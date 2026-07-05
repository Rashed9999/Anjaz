<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-INSIDER-001 — إضافة 'search' لأنواع الوصول في سجل اطّلاع PII
 * (عمليات البحث الواسعة مؤشر داخلي مهم — تُسجَّل وتُحتسب في كشف الشذوذ).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE pii_access_logs
            MODIFY access_type ENUM('view','decrypt_file','export','search') NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE pii_access_logs
            MODIFY access_type ENUM('view','decrypt_file','export') NOT NULL
        ");
    }
};
