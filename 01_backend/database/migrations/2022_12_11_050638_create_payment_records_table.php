<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payment_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('merchant_user_id')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->float('amount', '14', '2')->default(0);
            $table->string('callback')->nullable();
            $table->tinyInteger('is_paid')->default(0)->comment('0=unpaid, 1=paid');
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
        Schema::dropIfExists('payment_records');
    }
}
