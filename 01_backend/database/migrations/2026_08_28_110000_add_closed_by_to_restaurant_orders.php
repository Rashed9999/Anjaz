<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** يغلق الكاشير/الموظف الطلب، لا جهاز POS. */
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('closed_by_user_id')
                ->nullable()
                ->after('opened_by')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropIndex(['closed_by_user_id']);
            $table->dropColumn('closed_by_user_id');
        });
    }
};
