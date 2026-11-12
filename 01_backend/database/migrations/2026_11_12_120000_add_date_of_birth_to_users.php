<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.date_of_birth` — يكتبه مسار «رفع الهوية» (`me/kyc/identity`) وطلبات تغيير البيان،
 * ويقرؤه إرسالُ الترقية إلى موثّق. لم تكن له هجرةٌ قط: القارئون القدامى كانوا يفحصون
 * `Schema::hasColumn` فيتخطّونه بصمت، أمّا مسار الترقية فيكتبه مباشرةً فيسقط بـ1054 على أيّ قاعدةٍ لا تملكه.
 * اكتُشف بالرحلة الحقيقية بين موثّق وأميال باي. الإضافةُ مشروطةٌ بغيابه، فلا تمسّ قاعدةً تملكه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'date_of_birth')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('date_of_birth')->nullable();
            });
        }
    }

    public function down(): void
    {
        // لا حذف: قد يكون العمود سابقاً لهذه الهجرة في قاعدةٍ قائمة وفيه بيانات.
    }
};
