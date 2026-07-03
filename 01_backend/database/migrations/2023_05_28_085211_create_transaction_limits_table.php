<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction_limits', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->integer('todays_count')->default(0);
            $table->decimal('todays_amount', 10, 2)->default(0);
            $table->integer('this_months_count')->default(0);
            $table->decimal('this_months_amount', 10, 2)->default(0);
            $table->string('type')->nullable()->comment('add_money, send_money, cash_out, send_money_request, withdraw_request');
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
        Schema::dropIfExists('transaction_limits');
    }
};
