<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-BUSINESS-AUDIT-001 — أحداث تدقيق حساسة من «أميال أعمال» (تغيير صلاحيات الموظفين،
 * قيود عكسية، إقفال فترات، طلبات استرداد…) تُحفظ هنا لامتثال التاجر. مرة واحدة لكل مرجع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_link_id');
            $table->string('event_ref', 40);
            $table->string('action', 64);
            $table->json('actor')->nullable();
            $table->json('context')->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['business_link_id', 'event_ref'], 'bae_link_ref_unique');
            $table->index(['business_link_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_audit_events');
    }
};
