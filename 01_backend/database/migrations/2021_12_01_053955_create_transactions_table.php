<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('ref_trans_id')->nullable();
            $table->string('transaction_type');
            $table->float('debit', 14, 2)->default(0);
            $table->float('credit', 14, 2)->default(0);
            $table->float('balance', 14, 2)->default(0);
            $table->bigInteger('from_user_id')->nullable();
            $table->bigInteger('to_user_id')->nullable();
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
        Schema::dropIfExists('transactions');
    }
}
