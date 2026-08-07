<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CATALOG-001 — كتالوج المنتجات المشترك بالباركود.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **البحث قبل الإنشاء (قاعدة حارس القاعدة):**
 *
 * قِيست الجداول القائمة فوُجدت ثلاثةٌ للمنتجات — `merchant_products`
 * و`wholesale_products` و`pharmacy_products` — **وكلُّها مقيَّدةٌ بمالك**
 * (`merchant_user_id` · `business_id` · `pharmacy_id`)، وكلُّها فيها
 * `barcode`. **ولا واحدَ منها كتالوجٌ عامّ.** فالجدولان هنا لا يُكرّران
 * شيئاً: هما الطبقةُ المشتركة تحتها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ جدولان لا جدول:**
 *
 * الباركودُ الواحد يحمل أسماءً مختلفة عند تجّار مختلفين — «شاي ليبتون
 * ١٠٠ كيس» و«ليبتون أحمر كبير»، **وكلاهما صحيح**. فلو كان الباركودُ
 * مفتاحاً فريداً في جدولٍ واحدٍ لضاع الاسمُ الثاني صامتاً.
 *
 *   `product_catalog_entries`     — صفٌّ واحدٌ لكلّ باركود: **الحقيقة**
 *   `product_catalog_suggestions` — ما أرسله كلُّ تاجر: **المُدخَل الخام**
 *
 * فالتعارضُ يُحفظ ويُعرض ويُحسم، لا يُبتلع.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما ليس في هذين الجدولين أهمُّ ممّا فيهما: لا سعرَ ولا كميّة.**
 *
 * السعرُ والتكلفةُ والكميّةُ والصلاحيّة تبقى في جدول التاجر وحده. وهذا
 * **حدٌّ بنيويّ لا وعدٌ في الواجهة**: رؤيةُ تاجرٍ لسعر منافسه تسريبٌ
 * تجاريّ يُفقد المنصّةَ ثقةَ التجّار دفعةً واحدة. ولا عمودَ هنا يحمله،
 * فلا يُسرَّب ولو أخطأ متحكّمٌ يوماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_catalog_entries')) {
            Schema::create('product_catalog_entries', function (Blueprint $table) {
                $table->id();
                $table->ulid('entry_ulid')->unique();

                // **الباركودُ هو المفتاح** — فريدٌ عالميّاً بحكم معيار EAN.
                $table->string('barcode', 32)->unique();

                $table->string('name', 160);
                $table->string('image_path', 255)->nullable();
                $table->string('category', 80)->nullable();
                $table->string('unit', 24)->nullable();   // قطعة · كيلو · لتر

                // proposed | verified | rejected
                $table->string('status', 16)->default('proposed')->index();

                // **عدّادُ التبنّي** — كم تاجراً استعمل هذا الصنف فعلاً.
                // يُحسب من الاستعمال لا يُكتب يدويّاً (القاعدة السادسة).
                $table->unsignedInteger('adoption_count')->default(0)->index();

                $table->unsignedBigInteger('proposed_by_user_id')->nullable()->index();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 255)->nullable();

                $table->timestamps();

                // **حذفٌ ناعمٌ لا صلب:** صنفٌ حُذف وقد تبنّاه تجّار — حذفُه
                // الصلب يترك منتجاتِهم بلا أصلٍ يُرجع إليه.
                $table->softDeletes();

                $table->index(['status', 'created_at']);
                $table->index('name');

                $table->foreign('proposed_by_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('reviewed_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('product_catalog_suggestions')) {
            Schema::create('product_catalog_suggestions', function (Blueprint $table) {
                $table->id();
                $table->string('barcode', 32)->index();
                $table->string('name', 160);
                $table->string('category', 80)->nullable();
                $table->string('unit', 24)->nullable();

                $table->unsignedBigInteger('merchant_user_id')->index();

                // pending | applied | dismissed
                $table->string('status', 16)->default('pending')->index();

                $table->timestamps();

                // **تاجرٌ واحدٌ لا يقترح الباركودَ نفسَه مرّتين** — وإلّا
                // امتلأت الشاشةُ بتكرارٍ من متجرٍ واحدٍ يُعيد الحفظ.
                $table->unique(['barcode', 'merchant_user_id'], 'pcs_barcode_merchant_unique');

                $table->index(['status', 'created_at']);

                $table->foreign('merchant_user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalog_suggestions');
        Schema::dropIfExists('product_catalog_entries');
    }
};
