<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMerchantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->string('store_name')->nullable();
            $table->string('callback')->nullable();
            $table->string('logo')->nullable();
            $table->string('address')->nullable();
            $table->string('bin')->nullable();
            $table->string('public_key')->nullable();
            $table->string('secret_key')->nullable();
            $table->string('merchant_number')->nullable();
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
        Schema::dropIfExists('merchants');
    }
}
