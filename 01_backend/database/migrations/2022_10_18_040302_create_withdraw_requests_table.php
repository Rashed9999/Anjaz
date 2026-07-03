<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWithdrawRequestsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('withdraw_requests', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->float('amount', 14, 2)->default(0);
            $table->string('request_status')->default('pending');
            $table->boolean('is_paid')->default(0);
            $table->string('sender_note')->nullable();
            $table->string('admin_note')->nullable();
            $table->bigInteger('withdrawal_method_id')->nullable();
            $table->text('withdrawal_method_fields')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('withdraw_requests');
    }
}
