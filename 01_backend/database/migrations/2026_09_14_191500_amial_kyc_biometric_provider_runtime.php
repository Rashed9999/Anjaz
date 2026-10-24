<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kyc_biometric_attempts')) {
            Schema::create('kyc_biometric_attempts', function (Blueprint $table) {
                $table->id();
                $table->char('attempt_ulid', 26)->unique();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('provider', 64)->index();
                $table->string('provider_reference', 160)->nullable();
                $table->string('status', 32)->default('starting')->index();
                $table->string('liveness_status', 24)->default('pending');
                $table->decimal('liveness_score', 7, 4)->nullable();
                $table->string('face_match_status', 24)->default('pending');
                $table->decimal('face_match_score', 7, 4)->nullable();
                $table->string('result_code', 80)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                // لا cascade: أثر التحقق الرقابي لا يختفي بحذف الحساب.
                $table->unique(['provider', 'provider_reference'], 'kyc_bio_provider_ref_uq');
                $table->index(['user_id', 'status'], 'kyc_bio_user_status_idx');
            });
        }

        if (!Schema::hasTable('kyc_biometric_events')) {
            Schema::create('kyc_biometric_events', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 64)->index();
                $table->char('event_key_hash', 64);
                $table->unsignedBigInteger('attempt_id')->nullable()->index();
                $table->string('event_type', 80);
                $table->char('payload_sha256', 64);
                $table->string('liveness_status', 24)->nullable();
                $table->decimal('liveness_score', 7, 4)->nullable();
                $table->string('face_match_status', 24)->nullable();
                $table->decimal('face_match_score', 7, 4)->nullable();
                $table->string('result_code', 80)->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['provider', 'event_key_hash'], 'kyc_bio_event_idempotency_uq');
                $table->index(['provider', 'created_at'], 'kyc_bio_event_provider_time_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_biometric_events');
        Schema::dropIfExists('kyc_biometric_attempts');
    }
};
