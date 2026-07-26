<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-FAVORITES-001 — توسيع المفضّلة من أرقام إلى كل ما يستحقّ التكرار.
 *
 * الجدول كان للأرقام وحدها (user_id, name, phone, type). والعميل يكرّر
 * أشياء أخرى: رقم حساب، تاجر يشتري منه أسبوعياً، وعملية بعينها يعيدها
 * بنفس المبلغ (إيجار، اشتراك، تحويل شهري لأهله).
 *
 * **لماذا توسيع لا جدول جديد:** جدول favourite_numbers يُغذّي خصم رسوم
 * التحويل والسحب في TransactionController (ثلاثة مواضع). جدول موازٍ
 * يعني نظامَي مفضّلة، وخصماً يعمل في أحدهما دون الآخر — وهو بالضبط نوع
 * الازدواج الذي كلّفنا وقتاً طويلاً في مسارَي الدخول والتسجيل.
 *
 * الأعمدة الجديدة:
 *   kind     contact | account | operation | merchant
 *   value    المعرّف: هاتف / رقم حساب / رقم إشعار / رقم تاجر
 *   metadata بيانات العرض (مبلغ، نوع عملية، اسم متجر) — لا تُقرأ للقرارات
 *
 * الصفوف الموجودة تُرحَّل إلى kind = 'contact' وvalue = phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('favourite_numbers', function (Blueprint $table) {
            if (!Schema::hasColumn('favourite_numbers', 'kind')) {
                $table->string('kind', 16)->default('contact')->after('user_id')->index();
            }
            if (!Schema::hasColumn('favourite_numbers', 'value')) {
                $table->string('value', 64)->nullable()->after('kind');
            }
            if (!Schema::hasColumn('favourite_numbers', 'metadata')) {
                $table->json('metadata')->nullable()->after('phone');
            }
        });

        // الهاتف صار اختيارياً: مفضّلة من نوع «عملية» لا هاتف لها.
        // نتجاوز بصمت إن لم تتوفّر doctrine/dbal — القيمة الافتراضية ''
        // تكفي، والكتابة تملأها.
        try {
            DB::statement('ALTER TABLE favourite_numbers MODIFY phone VARCHAR(191) NULL');
        } catch (\Throwable) {
            // لا يمنع بقية الترحيل
        }

        // ترحيل الصفوف القائمة: كلها جهات اتصال بحكم الجدول القديم.
        DB::table('favourite_numbers')
            ->where(fn ($q) => $q->whereNull('value')->orWhere('value', ''))
            ->update(['value' => DB::raw('phone'), 'kind' => 'contact']);

        // لا تكرار لنفس القيمة من نفس النوع لنفس المستخدم.
        Schema::table('favourite_numbers', function (Blueprint $table) {
            try {
                $table->unique(['user_id', 'kind', 'value'], 'fav_user_kind_value_unique');
            } catch (\Throwable) {
                // موجود مسبقاً
            }
        });
    }

    public function down(): void
    {
        Schema::table('favourite_numbers', function (Blueprint $table) {
            try {
                $table->dropUnique('fav_user_kind_value_unique');
            } catch (\Throwable) {
            }
            foreach (['kind', 'value', 'metadata'] as $column) {
                if (Schema::hasColumn('favourite_numbers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
