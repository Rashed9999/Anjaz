<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-BRANCH-CUSTODY-001 — الفرع جزء من حقيقة القبض، لا فلتر تقرير.
 *
 * قبل هذا التغيير كانت الوردية تحمل الموظف والجهاز، والبيعة تحمل الوردية
 * والجهاز، لكن أيّاً منهما لا يحمل الفرع. عند نقل موظف/جهاز لاحقاً يصبح
 * تقرير الشهر الماضي قابلاً للتفسير بأكثر من فرع. نلتقط الفرع وقت الحدث
 * ونحرس التطابق عند كل عملية جديدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cashier_shifts', 'merchant_sales'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->index(['merchant_user_id', 'branch_id'], $table.'_merchant_branch_idx');
            });
        }

        $this->backfillShifts();
        $this->backfillSales();
    }

    private function backfillShifts(): void
    {
        if (! Schema::hasTable('cashier_shifts') || ! Schema::hasColumn('cashier_shifts', 'branch_id')) {
            return;
        }

        DB::table('cashier_shifts')->whereNull('branch_id')->orderBy('id')
            ->chunkById(250, function ($rows) {
                foreach ($rows as $shift) {
                    $branchId = $this->branchFor(
                        (int) $shift->merchant_user_id,
                        $shift->pos_device_id ?? null,
                        $shift->pos_user_id ?? null,
                    );
                    if ($branchId !== null) {
                        DB::table('cashier_shifts')->where('id', $shift->id)
                            ->update(['branch_id' => $branchId]);
                    }
                }
            }, 'id');
    }

    private function backfillSales(): void
    {
        if (! Schema::hasTable('merchant_sales') || ! Schema::hasColumn('merchant_sales', 'branch_id')) {
            return;
        }

        DB::table('merchant_sales')->whereNull('branch_id')->orderBy('id')
            ->chunkById(250, function ($rows) {
                foreach ($rows as $sale) {
                    $branchId = null;
                    if (! empty($sale->shift_id) && Schema::hasTable('cashier_shifts')) {
                        $branchId = DB::table('cashier_shifts')->where('id', $sale->shift_id)
                            ->value('branch_id');
                    }
                    $branchId ??= $this->branchFor(
                        (int) $sale->merchant_user_id,
                        $sale->pos_device_id ?? null,
                        $sale->pos_user_id ?? null,
                    );
                    if ($branchId !== null) {
                        DB::table('merchant_sales')->where('id', $sale->id)
                            ->update(['branch_id' => $branchId]);
                    }
                }
            }, 'id');
    }

    private function branchFor(int $merchantId, mixed $deviceId, mixed $posUserId): ?int
    {
        if ($deviceId && Schema::hasTable('merchant_pos_devices')) {
            $id = DB::table('merchant_pos_devices')->where('id', $deviceId)
                ->where('merchant_user_id', $merchantId)->where('is_active', true)
                ->value('branch_id');
            if ($id !== null) return (int) $id;
        }
        if ($posUserId && Schema::hasTable('pos_users')) {
            $id = DB::table('pos_users')->where('id', $posUserId)
                ->where('merchant_user_id', $merchantId)->where('is_active', true)
                ->value('branch_id');
            if ($id !== null) return (int) $id;
        }
        if (Schema::hasTable('branches')) {
            $id = DB::table('branches')->where('merchant_user_id', $merchantId)
                ->where('is_active', true)->where('is_default', true)->value('id');
            if ($id !== null) return (int) $id;
        }

        return null; // تاريخٌ أقدم من الفروع: لا نخترع له موقعاً.
    }

    public function down(): void
    {
        foreach (['cashier_shifts', 'merchant_sales'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('branch_id'));
            }
        }
    }
};
