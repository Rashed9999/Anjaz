<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_printer_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('profile_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('pos_device_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('printer_type', 32)->default('thermal');
            $table->string('connection_type', 24);
            $table->string('paper_size', 24);
            $table->string('endpoint_hash', 64)->index();
            $table->string('endpoint_hint', 120)->nullable();
            $table->boolean('is_default')->default(true);
            $table->string('status', 24)->default('active');
            $table->json('capabilities')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();

            $table->index(
                ['merchant_user_id', 'pos_device_id', 'is_default'],
                'printer_profiles_owner_device_default'
            );
        });

        Schema::create('merchant_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_ulid', 26)->unique();
            $table->string('client_job_id', 100)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('pos_user_id')->nullable()->index();
            $table->unsignedBigInteger('pos_device_id')->nullable()->index();
            $table->unsignedBigInteger('printer_profile_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by_user_id')->index();
            $table->string('document_type', 64)->index();
            $table->string('document_id', 120)->nullable()->index();
            $table->string('document_number', 120)->nullable();
            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->text('result_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['merchant_user_id', 'status', 'created_at'],
                'print_jobs_merchant_status_date'
            );
            $table->index(
                ['merchant_user_id', 'document_type', 'document_id'],
                'print_jobs_document_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_print_jobs');
        Schema::dropIfExists('merchant_printer_profiles');
    }
};
