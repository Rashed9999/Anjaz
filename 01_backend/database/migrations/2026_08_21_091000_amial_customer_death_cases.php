<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_death_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 20)->unique();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->unsignedBigInteger('approval_request_id')->unique();
            $table->unsignedBigInteger('opened_by')->index();
            $table->unsignedBigInteger('reviewer_id')->nullable()->index();
            $table->text('evidence_summary');
            $table->string('status', 30)->index(); // pending_review | confirmed | rejected
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_death_cases');
    }
};
