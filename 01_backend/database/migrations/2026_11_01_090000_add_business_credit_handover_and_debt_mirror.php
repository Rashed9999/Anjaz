<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-BUSINESS-CREDIT-001 — تسليم الآجل لأميال أعمال ومرآة الديون.
 *
 *   business_links.credit_handed_over_at  متى صارت أميال أعمال مصدر الحقيقة
 *                                          الوحيد لديون التاجر (عملاء وموردين).
 *                                          بعدها يرفض أميال باي أي حركة آجل له.
 *   business_customer_debts                مرآة للقراءة فقط لما يدين به العميل
 *                                          للتاجر في أميال أعمال، كي يراه في
 *                                          تطبيقه ويسدّده. ليست دفتراً: لا تُعدَّل
 *                                          إلا بنسخة أحدث من أميال أعمال.
 *   business_payment_intents.purpose…     نيّة لتحصيل دين (لا لفاتورة) ومن أنشأها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_links', function (Blueprint $table) {
            $table->timestamp('credit_handed_over_at')->nullable()->after('status_changed_at');
            $table->string('credit_handover_reference', 64)->nullable()->after('credit_handed_over_at');
            $table->char('credit_handover_fingerprint', 64)->nullable()->after('credit_handover_reference');
        });

        Schema::table('business_payment_intents', function (Blueprint $table) {
            $table->string('purpose', 24)->default('sale')->after('business_reference');
            $table->string('origin', 16)->default('business')->after('purpose');
            $table->string('contact_ref', 64)->nullable()->after('origin');
            $table->unsignedBigInteger('payer_user_id')->nullable()->after('contact_ref');
        });

        Schema::create('business_customer_debts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_link_id');
            $table->string('contact_ref', 64);
            $table->unsignedBigInteger('customer_user_id')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('customer_name', 191);
            $table->decimal('balance', 24, 4)->default(0);
            $table->decimal('overdue_amount', 24, 4)->default(0);
            $table->date('next_due_date')->nullable();
            $table->char('currency', 3)->default('YER');
            $table->boolean('reminders_enabled')->default(true);
            $table->unsignedBigInteger('version');
            $table->timestamp('as_of');
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['business_link_id', 'contact_ref'], 'bcd_link_contact_unique');
            $table->index('customer_user_id');
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_customer_debts');
        Schema::table('business_payment_intents', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'origin', 'contact_ref', 'payer_user_id']);
        });
        Schema::table('business_links', function (Blueprint $table) {
            $table->dropColumn(['credit_handed_over_at', 'credit_handover_reference', 'credit_handover_fingerprint']);
        });
    }
};
