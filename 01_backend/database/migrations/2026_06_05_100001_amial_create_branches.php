<?php

/**
 * P1-BRANCHES — جدول الفروع.
 *
 * كل تاجر يمكن أن يملك عدّة فروع:
 *   - الخطّة FREE/STARTER/BUSINESS: 0 فروع (فرع افتراضي ضمني فقط)
 *   - MERCHANT_PRO: حتّى 3 فروع
 *   - ENTERPRISE: غير محدود
 *
 * كل فرع يحوي:
 *   - معرّفات + بيانات اتصال
 *   - مدير اختياري (PosUser من نوع manager)
 *   - الإعدادات (الفئات الافتراضية، الطباعة، إلخ)
 *   - حالة (active/inactive)
 *
 * الـ default branch:
 *   - يُنشأ تلقائياً لكل تاجر عند الترقية للخطة المُدعومة
 *   - لا يمكن حذفه
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            // الـ owner (التاجر)
            $table->unsignedBigInteger('merchant_user_id');
            $table->index('merchant_user_id', 'br_merchant');

            // بيانات الفرع
            $table->string('name', 100);                  // "فرع الغيضة"
            $table->string('code', 20)->nullable();       // "GH01" — اختياري للداخلي
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('phone', 32)->nullable();

            // المدير الافتراضي (PosUser)
            $table->unsignedBigInteger('manager_pos_user_id')->nullable();
            $table->index('manager_pos_user_id', 'br_manager');

            // إعدادات
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // الفرع الافتراضي (لا يُحذَف)
            $table->json('settings')->nullable();          // print_template, opening_hours, etc.

            // الـ counters (cached للأداء)
            $table->unsignedInteger('cached_pos_users_count')->default(0);
            $table->unsignedInteger('cached_terminals_count')->default(0);

            // معرّفات الفرع
            $table->string('ulid', 26)->unique();         // معرّف نصّي مستقرّ للمشاركة

            $table->timestamps();
            $table->softDeletes();                         // soft delete (لا نفقد التاريخ)

            // فهارس مساعدة
            $table->index(['merchant_user_id', 'is_active'], 'br_merchant_active');
            $table->unique(['merchant_user_id', 'name'], 'br_merchant_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
