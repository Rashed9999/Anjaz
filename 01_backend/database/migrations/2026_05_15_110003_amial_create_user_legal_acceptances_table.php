<?php

/**
 * AMIAL-LEGAL-001
 *
 * user_legal_acceptances: سجل قبول المستخدمين لكل إصدار من السياسة.
 *
 * قواعد:
 *   - كل user يقبل كل version مرة واحدة فقط.
 *   - حقول التتبع (IP, user_agent, device_id) لازمة قانونياً.
 *   - لا يجوز حذف صف هنا (append-only للأمان القانوني).
 *
 * استعلام شائع:
 *   "هل المستخدم قبل آخر إصدار حالي؟"
 *   SELECT 1 FROM user_legal_acceptances
 *   WHERE user_id = ? AND legal_term_id IN (
 *     SELECT id FROM legal_terms WHERE is_current = 1 AND locale = ?
 *   )
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_legal_acceptances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('legal_term_id');

            // snapshot للنسخة المقبولة (لو تغيرت لاحقاً نعرف ماذا قبل المستخدم)
            $table->string('accepted_version', 32);

            // معلومات قانونية للإثبات
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_id', 128)->nullable();

            $table->timestamp('accepted_at')->useCurrent();
            // لا updated_at — append-only

            // فهارس + unique لمنع قبول مكرر لنفس الإصدار
            $table->unique(['user_id', 'legal_term_id'], 'user_legal_unique');
            $table->index(['user_id', 'accepted_at'], 'user_legal_user_idx');
            $table->index('legal_term_id', 'user_legal_term_idx');

            // ربط FK لـ rollover حذف (يجب نادراً)
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('legal_term_id')
                ->references('id')->on('legal_terms')
                ->onDelete('restrict'); // لا نسمح بحذف legal_term إن كان مقبولاً من أحد
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_legal_acceptances');
    }
};
