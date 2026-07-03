<?php

/**
 * AMIAL-WA-002 — سجل تدقيق شامل للبوت (Section 27-28 من المواصفة).
 *
 * يسجّل: الأوامر، التحويلات، الفشل، النجاح، التأكيدات، محاولات الاختراق.
 * منفصل عن settlement_audit_logs (USE-001) — هذا خاصّ بقناة واتساب فقط.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_number', 20)->index();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('event_type', 32);
            // command | transfer_attempt | transfer_success | transfer_failed
            // link_attempt | link_success | link_failed | security_flag

            $table->string('intent', 32)->nullable();   // balance | transfer | ...
            $table->string('outcome', 16)->default('success'); // success | failed | blocked
            $table->unsignedTinyInteger('risk_delta')->default(0);

            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['whatsapp_number', 'created_at'], 'wal_wa_time');
            $table->index('event_type', 'wal_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_audit_logs');
    }
};
