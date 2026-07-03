<?php

/**
 * USE-001 — سجل التدقيق الكامل للتسويات (Immutable).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settlement_id');
            $table->index('settlement_id', 'sal_settlement');

            $table->string('action', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_role', 24)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('external_reference', 100)->nullable();

            $table->decimal('balance_before', 18, 4)->nullable();
            $table->decimal('balance_after', 18, 4)->nullable();

            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['settlement_id', 'created_at'], 'sal_settlement_time');
            $table->index('action', 'sal_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_audit_logs');
    }
};
