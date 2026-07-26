<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SAFEPAY-CODE-001 — رمز تأكيد التسليم.
 *
 * أكثر نزاعات الضمان شيوعاً نزاع لا دليل فيه لأيّ طرف: «سلّمتُه» مقابل
 * «لم يصلني». الصور لا تحسمه — صورة طرد لا تُثبت أنه وصل صاحبه.
 *
 * الحلّ الذي تستعمله شركات التوصيل: رمز يملكه المشتري وحده، يعطيه للبائع
 * لحظة الاستلام. البائع لا يستطيع تأكيد التسليم بلا الرمز، والمشتري لا
 * يعطيه إلا وقد استلم فعلاً. فالتسليم يصير حدثاً موثّقاً بطرفين لا ادّعاءً
 * من طرف.
 *
 * ملاحظة على النطاق: الرمز يحسم التسليم المباشر (يداً بيد) وهو الغالب في
 * السوق المحلّي. الشحن البعيد يبقى محتاجاً للصور وإيصال الناقل — ولذلك
 * الرمز إضافة إلى الأدلّة لا بديل عنها.
 *
 * التخزين مشفّر لا مُعمّى (Crypt لا Hash): المشتري يحتاج رؤية الرمز في
 * تطبيقه لحظة التسليم، والتعمية أحادية الاتجاه تمنع ذلك. التشفير يربط
 * قراءته بـ APP_KEY — تسريب قاعدة البيانات وحدها لا يكشفه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('safe_payments', 'delivery_code_hash')) {
                // نصّ لا سلسلة قصيرة: القيمة مشفّرة (قابلة للفكّ) لا مُعمّاة.
                $table->text('delivery_code_hash')->nullable()->after('status');
            }
            if (!Schema::hasColumn('safe_payments', 'delivery_code_verified_at')) {
                $table->timestamp('delivery_code_verified_at')->nullable()->after('delivery_code_hash');
            }
            if (!Schema::hasColumn('safe_payments', 'delivery_code_attempts')) {
                $table->unsignedTinyInteger('delivery_code_attempts')->default(0)
                    ->after('delivery_code_verified_at');
            }
            // AMIAL-SAFEPAY-DISPUTE-001: سبب النزاع منظّماً بجانب نصّه الحرّ
            if (!Schema::hasColumn('safe_payments', 'dispute_reason_code')) {
                $table->string('dispute_reason_code', 40)->nullable()->after('disputed_at')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('safe_payments', function (Blueprint $table) {
            foreach ([
                'delivery_code_hash', 'delivery_code_verified_at',
                'delivery_code_attempts', 'dispute_reason_code',
            ] as $column) {
                if (Schema::hasColumn('safe_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
