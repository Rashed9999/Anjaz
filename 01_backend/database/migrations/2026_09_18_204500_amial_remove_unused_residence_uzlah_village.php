<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-YEMEN-REGIONS-002
 *
 * رحلة عنوان العميل تعتمد فقط:
 * محافظة -> مديرية -> اسم حي/منطقة نصي.
 *
 * لا نحتاج أعمدة العزلة أو القرية في حساب العميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'residence_uzlah_geo_id',
                'residence_village_geo_id',
                'residence_uzlah',
                'residence_village',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'residence_uzlah_geo_id')) {
                $table->unsignedInteger('residence_uzlah_geo_id')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'residence_village_geo_id')) {
                $table->unsignedInteger('residence_village_geo_id')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'residence_uzlah')) {
                $table->string('residence_uzlah', 160)->nullable();
            }
            if (! Schema::hasColumn('users', 'residence_village')) {
                $table->string('residence_village', 180)->nullable();
            }
        });
    }
};
