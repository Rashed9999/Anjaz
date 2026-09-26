<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CUSTOMER-BIRTH-RESIDENCE-001
 *
 * birth_governorate = محافظة الميلاد (بيان هوية/تعريف).
 * residence_governorate = محافظة السكن الحالي (بيان إقامة).
 *
 * لا يُستخدم أيّ منهما لمنع إنشاء الحساب. نطاق التشغيل سياسة خدمة مستقلة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'birth_governorate')) {
                $table->string('birth_governorate', 8)
                    ->nullable()
                    ->after('origin_governorate')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'birth_governorate')) {
                $table->dropColumn('birth_governorate');
            }
        });
    }
};
