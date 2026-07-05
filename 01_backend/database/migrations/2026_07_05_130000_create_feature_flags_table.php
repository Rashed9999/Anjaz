<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MAINT-001 — لوحة «الصيانة الأولية»: مفاتيح تشغيل/إيقاف لكل ميزة.
 *
 * الفكرة: التحكّم بـ ~75% من ميزات المنصّة عبر أزرار من لوحة الأدمن دون
 * لمس الباكند. شرط الأمان الحاسم: لا تُغلق ميزة إلا إذا كانت تراكماتها
 * المالية = صفر (لا أموال عملاء محتجزة داخلها).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();          // safe_payment, family_fund, ...
            $table->string('name_ar', 120);
            $table->string('description_ar', 255)->nullable();
            $table->string('category', 40)->default('general'); // financial|merchant|channel|general
            // مصدر التراكم المالي (اسم دالة الحساب). null = لا أموال (يُغلق بحرّية).
            $table->string('money_source', 40)->nullable();
            $table->boolean('enabled')->default(true)->index();
            // ميزة أساسية: تحذير إضافي عند الإيقاف (لكن نفس قاعدة الأموال تنطبق).
            $table->boolean('is_core')->default(false);
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->text('last_note')->nullable();          // سبب آخر تغيير
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
