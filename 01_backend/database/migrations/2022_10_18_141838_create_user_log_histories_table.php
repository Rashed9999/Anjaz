<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserLogHistoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_log_histories', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('device_id')->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable();
            $table->string('device_model')->nullable();
            $table->bigInteger('user_id');
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
        Schema::dropIfExists('user_log_histories');
    }
}
