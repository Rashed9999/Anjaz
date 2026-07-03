<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPendingBalanceInEMoneyTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('e_money', function (Blueprint $table) {
            $table->double('pending_balance')->default(0.0)->after('charge_earned');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('e_money', function (Blueprint $table) {
            $table->dropColumn('pending_balance');
        });
    }
}
