<?php

/**
 * AMIAL-FUND-FAMILY-001 (v0.9-B)
 *
 * family_fund_transactions: سجل كل حركة على رصيد الصندوق.
 *
 * **append-only** — لا UPDATE ولا DELETE (مبدأ شفافية ال صندوق المشترك).
 * كل عملية لها balance_before/balance_after للمراجعة.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_fund_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('tx_ulid', 26)->unique();
            $table->unsignedBigInteger('fund_id');
            $table->unsignedBigInteger('user_id'); // من قام بالعملية

            $table->enum('tx_type', [
                'contribute',          // عضو يضيف مال للصندوق (من محفظته)
                'disburse_to_member',  // صرف من الصندوق لعضو
                'disburse_to_external',// صرف لـ user خارج الصندوق
                'adjustment',          // تعديل إداري (نادر)
            ])->index();

            $table->decimal('amount', 20, 4);
            $table->decimal('balance_before', 20, 4);
            $table->decimal('balance_after', 20, 4);

            // المرجع لعملية المحفظة الأصلية
            $table->string('wallet_transaction_id', 32)->nullable();

            // المستفيد (لو disbursement)
            $table->unsignedBigInteger('beneficiary_user_id')->nullable();

            $table->string('note', 500)->nullable();
            $table->string('attachment_path', 500)->nullable(); // مرفق اختياري

            // حالة (للـ approval flow)
            $table->enum('status', [
                'completed',
                'pending_approval',  // مقترَح، ينتظر owner
                'rejected',
                'cancelled',
            ])->default('completed')->index();

            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            // لا updated_at — append-only

            $table->index(['fund_id', 'created_at']);
            $table->index(['user_id', 'created_at']);

            $table->foreign('fund_id')->references('id')->on('family_funds')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('beneficiary_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_fund_transactions');
    }
};
