<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnToUserTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('identification_type')->nullable();
            $table->string('identification_number')->nullable();
            $table->string('identification_image')->default(json_encode([]));
            $table->string('is_kyc_verified')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('identification_type');
            $table->dropColumn('identification_number');
            $table->dropColumn('identification_image');
            $table->dropColumn('is_kyc_verified');
        });
    }
}
