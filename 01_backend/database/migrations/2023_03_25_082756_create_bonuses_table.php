<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBonusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bonuses', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('user_type', 50)->nullable(); //agent, customer
            $table->float('min_add_money_amount', 14, 2)->default(0);
            $table->integer('limit_per_user')->default(0);

            $table->string('bonus_type', 50)->nullable(); //Percentage, flat
            $table->float('bonus', 14, 2)->default(0);
            $table->float('max_bonus_amount', 14, 2)->default(0);

            $table->dateTime('start_date_time')->nullable();
            $table->dateTime('end_date_time')->nullable();

            $table->boolean('is_active')->default(1);

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
        Schema::dropIfExists('bonuses');
    }
}
