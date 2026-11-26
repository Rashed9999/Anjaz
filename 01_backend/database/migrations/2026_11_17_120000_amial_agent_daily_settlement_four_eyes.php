<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGT-GAP-018 — أعمدة Four-Eyes للتسوية اليوميّة للوكلاء (موافقة أولى + ثانية + عملة + رافع الطلب).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_daily_settlements', function (Blueprint $table) {
            if (!Schema::hasColumn('agent_daily_settlements', 'currency')) {
                $table->string('currency', 8)->nullable()->after('conversion_amount');
            }
            if (!Schema::hasColumn('agent_daily_settlements', 'submitted_by_user_id')) {
                $table->unsignedBigInteger('submitted_by_user_id')->nullable()->after('submitted_by_staff_id');
            }
            if (!Schema::hasColumn('agent_daily_settlements', 'approvals_required')) {
                $table->unsignedTinyInteger('approvals_required')->default(1)->after('status');
            }
            if (!Schema::hasColumn('agent_daily_settlements', 'first_approved_by_user_id')) {
                $table->unsignedBigInteger('first_approved_by_user_id')->nullable()->after('approvals_required');
            }
            if (!Schema::hasColumn('agent_daily_settlements', 'first_approved_at')) {
                $table->timestamp('first_approved_at')->nullable()->after('first_approved_by_user_id');
            }
            if (!Schema::hasColumn('agent_daily_settlements', 'second_approved_by_user_id')) {
                $table->unsignedBigInteger('second_approved_by_user_id')->nullable()->after('first_approved_at');
            }
            if (!Schema::hasColumn('agent_daily_settlements', 'second_approved_at')) {
                $table->timestamp('second_approved_at')->nullable()->after('second_approved_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_daily_settlements', function (Blueprint $table) {
            foreach ([
                'second_approved_at',
                'second_approved_by_user_id',
                'first_approved_at',
                'first_approved_by_user_id',
                'approvals_required',
                'submitted_by_user_id',
                'currency',
            ] as $col) {
                if (Schema::hasColumn('agent_daily_settlements', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
