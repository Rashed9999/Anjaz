<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** يحتفظ بمن نفّذ البيع، مستقلاً عن مقعد جهاز POS. */
    public function up(): void
    {
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')
                ->nullable()
                ->after('pos_user_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->dropIndex(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
        });
    }
};
