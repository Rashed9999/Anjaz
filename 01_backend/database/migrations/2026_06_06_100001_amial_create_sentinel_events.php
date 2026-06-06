<?php

/**
 * AMIAL-SENTINEL-001 — جدول أحداث الحارس المخفي.
 *
 * مستقل عن account_security_events لأن المتسلل غالباً غير مُصادَق (user_id null)،
 * بينما account_security_events يتطلّب user_id (أحداث موجّهة للمستخدم).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentinel_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('path', 500)->nullable();

            $table->unsignedSmallInteger('threat_score')->default(0);
            $table->enum('severity', ['info', 'notice', 'warning', 'critical'])->default('info');

            // قائمة رموز التوقيعات المطابِقة
            $table->json('signatures')->nullable();

            // الإجراء المتخذ: monitor / challenge / block
            $table->string('action', 16)->default('monitor');

            $table->string('request_id', 64)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['ip_address', 'created_at'], 'sentinel_ip_idx');
            $table->index(['severity', 'created_at'], 'sentinel_severity_idx');
            $table->index(['user_id', 'created_at'], 'sentinel_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentinel_events');
    }
};
