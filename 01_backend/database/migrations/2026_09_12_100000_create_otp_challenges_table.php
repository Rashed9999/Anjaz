<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('challenge_id', 26)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('identifier', 320)->index();
            $table->string('channel', 16)->default('email');
            $table->string('purpose', 32)->index();
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('resend_available_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_token_hash')->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->string('delivery_status', 24)->default('pending')->index();
            $table->string('provider_message_id', 128)->nullable()->index();
            $table->text('last_error')->nullable();
            $table->string('requested_by_type', 20)->nullable();
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['identifier', 'purpose', 'created_at'], 'otp_identifier_purpose_created_idx');
            $table->index(['purpose', 'delivery_status', 'created_at'], 'otp_purpose_delivery_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
