<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AMIAL-FIX(DEPLOY): جداول الطوابير القياسية (jobs) كانت مفقودة — إشعارات ما بعد
// commit تُصفّ لجدول jobs (QUEUE_CONNECTION=database) فتفشل بلا هذه الجداول.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        if (!Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
    }
};
