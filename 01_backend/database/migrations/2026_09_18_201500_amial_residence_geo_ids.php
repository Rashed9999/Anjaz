<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-YEMEN-REGIONS-001
 *
 * نخزن المعرّفات المعيارية مع الأسماء البشرية. بقاء الاسم مهم للتقارير
 * التاريخية، والمعرّف يمنع الغموض ويتيح التحقق من السلسلة الجغرافية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'residence_district_geo_id')) {
                $table->unsignedInteger('residence_district_geo_id')->nullable()->index();
            }
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

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'residence_district_geo_id',
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
};
