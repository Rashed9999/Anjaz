<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل تدقيق صادر من موثّق يُحفظ في أميال باي.
 *
 * لا يحفظ مستندات أو أرقام هوية أو كلمات مرور؛ فقط من قام بالفعل ومتى
 * وعلى أي طلب فتح حساب. event_ulid يجعل إعادة الإرسال آمنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muwathaq_reviewer_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_ulid', 26)->unique();
            $table->string('reviewer_identity', 190);
            $table->string('action', 64);
            $table->string('application_ulid', 26)->nullable()->index();
            $table->string('case_ulid', 26)->nullable()->index();
            $table->string('subject_kind', 32)->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['reviewer_identity', 'occurred_at'], 'muwathaq_reviewer_audit_actor_time');
            $table->index(['action', 'occurred_at'], 'muwathaq_reviewer_audit_action_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muwathaq_reviewer_audit_events');
    }
};
