<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AUDIT-ORPHAN-001 — جدول addon_settings المفقود.
 *
 * نموذج `Setting` يشير إلى جدول `addon_settings` ولم يُنشأ قطّ في أميال:
 * جاء من قالب 6cash ولم تُنقل هجرته. النتيجة أن كل مسار يمرّ به يرمي
 * «Base table or view not found» — خطأ 500 صريح:
 *
 *   • GET/POST /api/v1/amial/admin/settings/sms       (إعدادات الرسائل)
 *   • GET/POST /api/v1/amial/admin/whatsapp/config    (إعدادات واتساب)
 *   • مسارات BusinessSettingsController في لوحة الويب
 *   • App\Traits\SmsGateway عند حفظ إعدادات المزوّد
 *
 * لم يُكتشف لأن هذه المسارات بلا اختبارات، ولأن الخطأ يظهر في شاشة إعدادات
 * لا في مسار مالي — فيبدو «عطلاً مؤقّتاً» لا خللاً بنيوياً.
 *
 * الأعمدة مشتقّة من استعمال الشيفرة نفسها لا من تخمين: key_name و
 * settings_type للبحث، و live_values/test_values حمولة JSON، و mode يختار
 * أيّهما يُقرأ، و is_active للتعطيل، و additional_data من $fillable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addon_settings')) {
            return;
        }

        Schema::create('addon_settings', function (Blueprint $table) {
            // النموذج يستعمل HasUuid — مفتاح رقمي تلقائي يرمي
            // «Data truncated for column id» عند أوّل إدراج.
            $table->uuid('id')->primary();
            $table->string('key_name', 100);
            $table->string('settings_type', 100);
            // نصّ طويل لا string: الحمولة JSON لإعدادات مزوّد كاملة.
            $table->longText('live_values')->nullable();
            $table->longText('test_values')->nullable();
            $table->string('mode', 20)->default('live');
            $table->boolean('is_active')->default(false);
            $table->longText('additional_data')->nullable();
            $table->timestamps();

            // updateOrCreate يبحث بهما معاً — الفهرس الفريد يمنع تكراراً
            // يجعل first() يُرجع صفّاً عشوائياً من صفّين متطابقي المفتاح.
            $table->unique(['key_name', 'settings_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_settings');
    }
};
