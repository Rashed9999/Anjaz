<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_id');
            $table->string('sender_type')->nullable();
            $table->unsignedBigInteger('transaction_id');
            $table->decimal('amount', 20, 2);
            $table->unsignedBigInteger('disputed_user_id');
            $table->string('status')->default('pending');
            $table->string('trx_id');
            $table->dateTime('sending_time')->default(now());
            $table->longText('report_reason')->nullable();
            $table->longText('comment')->nullable();
            $table->longText('denied_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
