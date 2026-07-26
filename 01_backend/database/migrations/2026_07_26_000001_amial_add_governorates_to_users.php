<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-GOVERNORATES-001 — محافظتا الأصل والسكن على المستخدم.
 *
 * قبلها كان العنوان نصّاً مشفّراً واحداً (address_encrypted) لا يُقارَن ولا
 * يُفهرَس، والمنطقة تُخمَّن من مطابقة نصّية على مدينة غير مخزّنة أصلاً.
 *
 * origin_governorate    = محافظة الأصل كما في الهوية
 * residence_governorate = محافظة السكن كما في وثيقة العنوان
 *
 * الفصل بينهما مقصود: يمنيّ أصله من إب ويسكن عدن حالة عادية جداً، والمنطقة
 * التشغيلية تتبع السكن لا الأصل. اجتماعهما يمنح المراجع ما يقارنه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'origin_governorate')) {
                $table->string('origin_governorate', 8)->nullable()->after('zone_code')->index();
            }
            if (!Schema::hasColumn('users', 'residence_governorate')) {
                $table->string('residence_governorate', 8)->nullable()->after('origin_governorate')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['origin_governorate', 'residence_governorate'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
