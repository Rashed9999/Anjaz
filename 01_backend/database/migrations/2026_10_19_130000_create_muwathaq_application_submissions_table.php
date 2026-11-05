<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صندوق إرسال موثوق من أميال باي إلى بوابة «موثّق».
 *
 * تسجيل العميل لا يعتمد على توفر موثّق في اللحظة نفسها: يُحفظ الطلب أولاً،
 * ثم يُعاد إرسال هذه الرسالة حتى تقبلها البوابة. لا تحتوي الرسالة كلمات مرور
 * أو PIN أو صور/أرقام مستندات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muwathaq_application_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')
                ->unique()
                ->constrained('muwathaq_account_opening_applications')
                ->cascadeOnDelete();
            $table->string('event_ulid', 26)->unique();
            $table->json('payload');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'muwathaq_submission_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muwathaq_application_submissions');
    }
};
