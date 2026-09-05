<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PRODUCT-ATTRIBUTES-001 — **السماتُ تُعرَّف مرّةً لا مع كلّ منتج.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما طلبه صاحبُ المشروع** (بصيغة WooCommerce): سماتٌ عامّةٌ تُعرَّف في
 * مكانٍ واحد — «اللون» وقيمُه، «المقاس» وقيمُه — ثمّ يُختار منها لكلّ
 * منتجٍ ما يُستعمَل في توليد الأنواع.
 *
 * **والقائمُ اليوم يقبل المحاورَ نصّاً حرّاً في كلّ توليد**:
 * `{"اللون":["أحمر","أزرق"]}`. وهو يعمل، **وثمنُه يظهر بعد المنتج
 * العاشر**:
 *
 * ① **الإملاءُ يفترق.** «أحمر» و«احمر» و«أحمر » ثلاثُ قيمٍ مختلفة، فيصير
 *    للتاجر ثلاثةُ متغيّراتٍ للون واحدٍ ينقسم مخزونُها بينها — **ولا
 *    يمسكه شيء**: كلُّها صفوفٌ سليمةٌ في جدولٍ سليم.
 *
 * ② **ولا تُصفَّى قائمةٌ ولا يُبنى تقريرٌ باللون** — لأنّ اللونَ ليس
 *    كياناً، بل نصٌّ داخل `variant_attributes` لكلّ صفّ.
 *
 * ③ **ويُعاد كتابةُ القيم في كلّ منتج** — وهو ما يشكو منه المستعمل.
 *
 * فصار للسمة كيانٌ وقيمٌ محفوظة. **والقديمُ يبقى يعمل**: التوليدُ ما زال
 * يقبل المحاورَ نصّاً (للتوافق ولحالةٍ عابرة)، والمكتبةُ تملأ الشاشةَ
 * فيختار منها ولا يكتب.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('merchant_attributes')) {
            Schema::create('merchant_attributes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable();
                $table->unsignedBigInteger('merchant_user_id')->index();
                $table->string('name', 60);              // اللون · المقاس
                $table->string('slug', 60);              // color · size
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                // **سمةٌ واحدةٌ بالاسم لكلّ تاجر** — ولولاه صار «اللون»
                // مرّتين وانقسمت قيمُه بينهما.
                $table->unique(['merchant_user_id', 'slug'], 'merchant_attribute_unique');
            });
        }

        if (!Schema::hasTable('merchant_attribute_terms')) {
            Schema::create('merchant_attribute_terms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attribute_id')->index();
                $table->unsignedBigInteger('merchant_user_id')->index();
                $table->string('value', 60);             // أحمر · L
                $table->string('slug', 60);
                // لونٌ يُعرَض في الشاشة (اختياريّ) — «أحمر» يُرى أحمرَ.
                $table->string('color_hex', 9)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['attribute_id', 'slug'], 'merchant_attribute_term_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_attribute_terms');
        Schema::dropIfExists('merchant_attributes');
    }
};
