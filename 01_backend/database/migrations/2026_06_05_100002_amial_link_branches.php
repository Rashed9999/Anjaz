<?php

/**
 * P1-BRANCHES-LINK — ربط الفروع بالعمليات الموجودة.
 *
 * يضيف branch_id لـ:
 *   - pos_users (الموظف ينتمي لفرع)
 *   - cashier_sales (كل بيعة كاشير لفرع)
 *   - fuel_sales (كل بيعة وقود لفرع)
 *   - pharmacy_sales (كل بيعة صيدلية لفرع)
 *   - wholesale_invoices (كل فاتورة جملة لفرع)
 *
 * كل branch_id:
 *   - nullable: لا نكسر البيانات القديمة
 *   - مُفهرس: للتقارير per-branch
 *   - بدون FK constraint: لو الفرع حُذف soft-delete نريد الإبقاء
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'pos_users' => 'pu_branch',
        'cashier_sales' => 'cs_branch',
        'fuel_sales' => 'fs_branch',
        'pharmacy_sales' => 'ps_branch',
        'wholesale_invoices' => 'wi_branch',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $indexName) {
            if (!Schema::hasTable($table)) continue;
            if (Schema::hasColumn($table, 'branch_id')) continue;

            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->unsignedBigInteger('branch_id')->nullable()->after('id');
                $t->index('branch_id', $indexName);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $indexName) {
            if (!Schema::hasTable($table)) continue;
            if (!Schema::hasColumn($table, 'branch_id')) continue;

            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropIndex($indexName);
                $t->dropColumn('branch_id');
            });
        }
    }
};
