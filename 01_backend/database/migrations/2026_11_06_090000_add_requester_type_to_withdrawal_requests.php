<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MERCHANT-CASHOUT-001 — سحب التاجر عبر الوكيل بالآلية نفسها لسحب العميل.
 * `requester_type` يفرّق سياستَي الطلب والتنفيذ (التاجر: PIN، حدود، تحقّق إلزامي
 * من الهوية عند الوكيل)، والصف نفسه يحمل الحجز والرسوم والقيود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->string('requester_type', 16)->default('customer')->after('customer_user_id');
            $table->index(['customer_user_id', 'requester_type', 'status'], 'wr_requester_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropIndex('wr_requester_status_idx');
            $table->dropColumn('requester_type');
        });
    }
};
