<?php

/**
 * AMIAL-RECEIPTS-001 (v0.9-A)
 *
 * receipts: سجل الإيصالات لكل عملية مالية ناجحة.
 *
 * المبادئ (قسم 17 من الوثيقة):
 *   - كل عملية مالية ناجحة لها record
 *   - PDF يُولّد عبر queue (ليس داخل مسار العملية المالية)
 *   - storage private فقط
 *   - QR + verification_code للتحقق من صحة الإيصال
 *   - يشمل كل العمليات: send, pay_merchant, POS, QR, refund,
 *     safe_payment, split_bill, settlements, family_fund
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();

            // معرف خارجي للعرض (المستخدم يراه)
            $table->string('receipt_number', 32)->unique();

            // كود تحقق قصير (للـ QR code) — random 16 chars
            $table->string('verification_code', 16)->unique();

            // نوع الإيصال
            $table->enum('receipt_type', [
                'send_money',
                'cash_in',
                'cash_out',
                'add_money',
                'withdraw',
                'pay_merchant',
                'pos_payment',
                'qr_payment',
                'refund',
                'safe_payment_funded',
                'safe_payment_released',
                'safe_payment_refunded',
                'split_bill_payment',
                'family_fund_contribute',
                'family_fund_disburse',
                'bank_settlement',
                'fee_charge',
            ])->index();

            // الـ user المالك للإيصال (من تظهر له الـ عملية في سجله)
            $table->unsignedBigInteger('user_id');

            // الـ counterparty (لو وُجد)
            $table->unsignedBigInteger('counterparty_user_id')->nullable();

            // الـ transaction المرجعية (transaction_id ULID)
            $table->string('reference_transaction_id', 32);

            // للعمليات المرتبطة بـ entity أخرى (safe payment id, family fund id, ...)
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // المبالغ (snapshot وقت إصدار الإيصال — لا تتغير)
            $table->decimal('amount', 20, 4);
            $table->decimal('fee', 20, 4)->default(0);
            $table->decimal('net_amount', 20, 4); // amount - fee (للمرسل) أو amount (للمستلم)

            // اتجاه (debit للمرسل / credit للمستلم)
            $table->enum('direction', ['debit', 'credit'])->index();

            // حالة الإيصال
            $table->enum('status', [
                'pending_pdf',      // record موجود، PDF لم يُولّد بعد (job في queue)
                'pdf_generated',    // PDF جاهز للتحميل
                'pdf_failed',       // فشل توليد الـ PDF (يُعاد المحاولة)
                'voided',           // أُلغي (نزاع، تصحيح إداري)
            ])->default('pending_pdf')->index();

            // مسار الـ PDF في private storage (لو مُولَّد)
            $table->string('pdf_storage_path', 500)->nullable();

            // metadata (JSON) — للحقول الخاصة لكل نوع
            $table->json('metadata')->nullable();

            // zone للتقارير
            $table->string('zone_code', 16)->default('SOUTH')->index();

            // وقت الإصدار (لا يتطابق دائماً مع وقت العملية الأصلية)
            $table->timestamp('issued_at')->useCurrent();

            // متى تم توليد الـ PDF (للمراقبة)
            $table->timestamp('pdf_generated_at')->nullable();

            // عدد مرات التحميل (للإحصاء)
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->timestamps();

            // فهارس للاستعلامات الشائعة
            $table->index(['user_id', 'issued_at'], 'receipts_user_date_idx');
            $table->index('reference_transaction_id', 'receipts_ref_tx_idx');
            $table->index(['reference_type', 'reference_id'], 'receipts_ref_entity_idx');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('counterparty_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
