<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-OPS-CONSOLE-001 — نظام تذاكر خدمة العملاء (النزاعات والبلاغات).
 *
 * كل بلاغ من عميل يصبح تذكرة برقم تسلسلي، تُسند لموظف، ولها خط زمني كامل
 * (أحداث: إنشاء/إسناد/تغيير حالة/ملاحظات/إجراءات) — كما تعمل أنظمة البنوك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 20)->unique();          // TKT-000001
            $table->unsignedBigInteger('user_id')->index();          // العميل صاحب البلاغ
            $table->unsignedBigInteger('opened_by_admin_id')->nullable(); // الموظف الذي فتح التذكرة
            $table->unsignedBigInteger('assigned_admin_id')->nullable()->index(); // الموظف المسؤول
            $table->string('transaction_ref', 40)->nullable()->index(); // مرجع العملية إن وُجد
            $table->string('category', 40)->default('other');
            // missing_transfer | wrong_recipient | forgot_pin | fraud_suspect | account_access | balance_issue | other
            $table->string('priority', 10)->default('normal');       // low|normal|high|urgent
            $table->string('status', 20)->default('open')->index();
            // open | investigating | waiting_customer | resolved | closed
            $table->string('subject', 200);
            $table->text('description')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'assigned_admin_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('support_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();
            $table->unsignedBigInteger('admin_id')->nullable();      // من قام بالحدث (null = نظام)
            $table->string('event_type', 30);
            // created | assigned | status_changed | note | action | priority_changed
            $table->string('old_value', 50)->nullable();
            $table->string('new_value', 50)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_tickets');
    }
};
