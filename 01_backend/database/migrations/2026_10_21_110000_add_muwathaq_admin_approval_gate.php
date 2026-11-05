<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * يفصل قرار التحقق عن قرار التفعيل المالي.
 * موثّق -> awaiting_admin_approval -> الإدارة -> approved/rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('muwathaq_account_opening_applications', function (Blueprint $table) {
            $table->timestamp('muwathaq_decided_at')->nullable()->after('decided_at');
            $table->unsignedBigInteger('admin_decided_by')->nullable()->after('muwathaq_decided_at');
            $table->timestamp('admin_decided_at')->nullable()->after('admin_decided_by');
            $table->string('admin_decision_reason', 1000)->nullable()->after('admin_decided_at');
            $table->index(['status', 'muwathaq_decided_at'], 'muwathaq_opening_admin_queue');
        });
    }

    public function down(): void
    {
        Schema::table('muwathaq_account_opening_applications', function (Blueprint $table) {
            $table->dropIndex('muwathaq_opening_admin_queue');
            $table->dropColumn(['muwathaq_decided_at', 'admin_decided_by', 'admin_decided_at', 'admin_decision_reason']);
        });
    }
};
