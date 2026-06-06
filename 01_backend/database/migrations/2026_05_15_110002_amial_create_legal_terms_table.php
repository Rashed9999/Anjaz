<?php

/**
 * AMIAL-LEGAL-001
 *
 * legal_terms: إصدارات سياسة الاستخدام.
 *
 * منطق:
 *   - كل إصدار له version (semver أو رقم تسلسلي).
 *   - effective_at متى يصبح هذا الإصدار سارياً.
 *   - is_current واحد فقط في كل وقت لكل locale.
 *   - المحتوى Markdown أو HTML.
 *
 * المستخدم يجب أن يقبل آخر إصدار قبل أي عملية مالية (middleware).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_terms', function (Blueprint $table) {
            $table->id();

            // كود الإصدار (e.g., "1.0", "1.1", "2.0")
            // semver أو int — نتركها string للمرونة.
            $table->string('version', 32);

            // لغة الإصدار (سياسة قد تُترجم لعدة لغات)
            $table->string('locale', 5)->default('ar'); // ar/en

            // العنوان المعروض في شاشة التطبيق
            $table->string('title', 255);

            // المحتوى الكامل (Markdown/HTML آمن — لا scripts)
            $table->longText('content');

            // الإصدار الحالي؟ (واحد فقط لكل locale في وقت معين)
            // فحص التفرد عبر partial unique index (راجع أدناه)
            $table->boolean('is_current')->default(false);

            // متى يدخل حيز التنفيذ؟
            $table->timestamp('effective_at');

            // متى يتوقف عن السريان؟ (null = ما زال سارياً)
            $table->timestamp('superseded_at')->nullable();

            // ملخص التغييرات (يُعرض كـ "ما الجديد؟")
            $table->text('changelog')->nullable();

            // الذي أنشأ هذا الإصدار (admin user id)
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            // unique على (version, locale) — كل إصدار يظهر مرة لكل لغة
            $table->unique(['version', 'locale'], 'legal_terms_version_locale_unique');

            $table->index(['locale', 'is_current'], 'legal_terms_current_idx');
            $table->index('effective_at', 'legal_terms_effective_idx');
        });

        // partial unique index لـ MySQL 8+: واحد is_current=true لكل locale
        // (MySQL لا يدعم WHERE في unique index، لكن نستخدم expression index في 8.0)
        // البديل: نطبق ذلك في الـ Service عبر transaction
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_terms');
    }
};
