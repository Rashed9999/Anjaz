<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-VERTICAL-COMPOSE-001 — **قطاعٌ يُنشَأ من اللوحة لا من نشرة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال الذي وُلد منه هذا الجدول:** «ماذا لو أردتُ إضافةَ قطاعٍ جديد؟
 * من نظري يبدو ذلك مستحيلاً — يحتاج ترميزاً، بينما البنيةُ التحتيّةُ
 * موجودة».
 *
 * **وقِيس فالجوابُ ثلاثُ طبقاتٍ لا طبقةٌ واحدة:**
 *
 *   ① الاسمُ والقدراتُ وعمقُ كلّ باقة   → **بياناتٌ** — وهي هذا الجدول
 *   ② الشاشاتُ المشتركة (كاشير · منتجات
 *      · مخزون · ديون · موردون · تقارير) → **مبنيّةٌ ومربوطةٌ بالقدرة**،
 *      فتصل القطاعَ الجديدَ بلا سطر Dart
 *   ③ محرّكُ قطاعٍ خاصّ (دفعاتُ الصيدليّة ·
 *      مضخّاتُ الوقود · مطبخُ المطعم)     → **شيفرةٌ حقيقيّة** — لا تُنشأ
 *      من لوحةٍ ولا يُدَّعى أنّها تُنشأ
 *
 * **والدليلُ أنّ ① و② يكفيان قطاعاً كاملاً مقيسٌ في المنتج نفسِه:**
 * `quick_sale` قطاعٌ عاملٌ منذ اليوم الأوّل **بصفر شاشةٍ خاصّةٍ به** —
 * لا مجلّدَ له في `lib/features/` إطلاقاً؛ هو تركيبٌ من الكاشير المشترك.
 * فمخبزٌ أو محلُّ ملابسَ أو مكتبةٌ ليست شاشاتٍ جديدة، بل **تركيبةٌ أخرى
 * فوق ما هو مبنيٌّ اليوم**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُخزَّن هنا قطاعٌ من الستّة المبنيّة.** الصفُّ لِما تُنشئه
 * الإدارة وحدَه، و`VerticalRegistry` يدمج المصدرين: الشيفرةُ للستّة،
 * والجدولُ لِما بعدها. فلا يُعدَّل قطاعٌ قائمٌ من هنا خطأً، ويبقى
 * `VerticalParityGuardTest` — الذي يُثبت تطابقَ ثماني عشرةَ تركيبة —
 * صحيحاً بلا شرط.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **و`core_features` مقابل `paid_depth` ليسا قائمتين لشيءٍ واحد:**
 * الأولى نواةُ القطاع — ما لا يعمل بدونه فيُمنَح في المجّانيّة أيضاً؛
 * والثانية ما **تبيعه** كلُّ باقةٍ فوقها. وخلطُهما هو ما جعل «كم منتجاً»
 * جواباً في موضعين يفترقان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_verticals', function (Blueprint $table) {
            $table->id();

            // رمزُه كما يُخزَّن في `merchant_profiles.business_type` —
            // ولذلك يوافق نمطَ الستّة المبنيّة: حروفٌ صغيرةٌ وشرطةٌ سفليّة.
            $table->string('code', 40)->unique();

            $table->string('name_ar', 60);
            $table->string('hint_ar', 120)->nullable();

            // أيقونةٌ ولونٌ لبطاقة الاختيار في التطبيق. ولا يُخترعان في
            // Dart: قطاعٌ تُنشئه الإدارةُ لا يعرفه ملفٌّ مُصرَّف.
            $table->string('icon', 40)->nullable();
            $table->string('color', 9)->nullable();

            $table->json('core_features');
            $table->json('paid_depth');

            // **الشاشةُ التي يفتح عليها التاجرُ تطبيقَه.** رمزُ قدرةٍ لا
            // مسارٌ نصّيّ — والمسارُ طرفٌ ثالثٌ لا يقرؤه أحد (وهو بعينه
            // العطلُ الذي وُلد منه `CapabilityScreens`).
            $table->string('home_capability', 60)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_verticals');
    }
};
