<?php

/**
 * AMIAL-SENTINEL-001 — قائمة حظر عناوين IP (تلقائي + يدوي).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentinel_blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('reason', 255)->nullable();
            $table->unsignedInteger('hits')->default(0);

            // null = حظر دائم؛ غير ذلك = حظر مؤقت ينتهي عند هذا الوقت
            $table->timestamp('blocked_until')->nullable();

            // 'auto' للحظر التلقائي، أو معرّف الأدمن للحظر اليدوي
            $table->string('created_by', 64)->default('auto');

            $table->timestamps();

            $table->index('blocked_until', 'sentinel_blocked_until_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentinel_blocked_ips');
    }
};
