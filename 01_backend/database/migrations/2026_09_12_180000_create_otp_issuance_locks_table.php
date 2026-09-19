<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A stable row also serializes the FIRST request for a mailbox/purpose.
        // Lock lifetime follows the outer DB transaction, including approvals.
        Schema::create('otp_issuance_locks', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_issuance_locks');
    }
};
