<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-POS-DEVICES-001 — **الجهازُ كِيانٌ، لا صفُّ موظّفٍ يُقرأ جهازاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في مراجعة الإنتاج:**
 *
 *     'max_employees'   => PosUser::where(...)->count()
 *     'max_pos_devices' => PosUser::where(...)->count()   ← الاستعلامُ نفسُه
 *
 * حدّان يُباعان بأرقامٍ مختلفة (البداية: موظّفون 0 · أجهزة 1) **ويعدّان
 * الصفوفَ نفسَها**. فباقةُ البداية تَعِد بصفر موظّفين وتُعطي واحداً،
 * وباقةُ الأعمال تبيع خمسةً وتُسلّم ثلاثة.
 *
 * ولم يكن في المخطّط ما يميّزهما: `pos_users` صفُّ **موظّف** (له
 * `user_id` وكلمةُ مرور)، ولا مسارَ يُنشئ جهازاً أصلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والفصلُ مفهوميٌّ قبل أن يكون جدولاً:**
 *
 *     تسجيلُ الجهاز  ≠  مصادقةُ الموظّف
 *
 * الجهازُ **ملكُ التاجر أو الفرع** — مقعدُ ترخيصٍ يُسجَّل مرّةً. والموظّفون
 * يتناوبون عليه بحساباتهم. فلا يُربط جهازٌ بموظّفٍ ربطاً دائماً، ولا
 * يستهلك موظّفٌ جديدٌ مقعدَ جهاز.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ قراراتٍ في هذا الجدول تُقرأ ولا تُخمَّن:**
 *
 * ① **`UNIQUE(merchant_user_id, device_uuid)`** — وهو ما يجعل إعادةَ
 *    التسجيل بالبصمة نفسِها **لا تستهلك مقعداً جديداً**، ويجعل السباقَ
 *    على آخر مقعدٍ يُحسَم في القاعدة لا في PHP. (والقيدُ **بالتاجر**
 *    لا عالميّاً: جهازٌ يُباع لتاجرٍ آخر يُسجَّل عنده من جديد.)
 *
 * ② **`revoked_at` لا حذف** — الجهازُ الملغى يخرج من المقاعد النشِطة
 *    **ويبقى أثرُه**. وحذفُه يمحو من أيّ جهازٍ خرجت عمليّاتُ أمس.
 *
 * ③ **لا سرَّ في الجدول.** `device_uuid` معرِّفٌ لا سرّ، ولا يُعرض كاملاً
 *    في أيّ شاشة. ولا يُخزَّن هنا موقعٌ ولا رقمُ هاتفٍ ولا أيُّ ما يجعل
 *    هذا تتبّعاً — المقصودُ **مقعدُ ترخيصٍ موثوق**، لا مراقبةُ حامله.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_pos_devices')) {
            return;
        }

        Schema::create('merchant_pos_devices', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('branch_id')->nullable();

            /**
             * **هويّةُ الجهاز — معرِّفٌ مستقرٌّ لا سرّ.**
             *
             * يُشتقّ في التطبيق من مصدرٍ يبقى عبر تحديثات النظام، ويُثبَّت
             * في مخزنٍ آمن بعد أوّل توليد. **وإعادةُ تثبيت التطبيق قد
             * تُغيّره** — وهذا مقبولٌ ومعلوم: يُعالَج بإلغاءِ المقعد القديم
             * من شاشة الإدارة، لا بمحاولة تتبّعٍ أعمق.
             *
             * ويُخزَّن **مجزَّأً** (‏hash) لا خاماً: فهو يُقارَن ولا يُقرأ،
             * وتسريبُ قاعدةٍ لا يُسلّم قائمةَ معرّفاتِ أجهزةٍ صالحة.
             */
            $table->string('device_uuid_hash', 64);

            /**
             * **إصدارُ المفتاح الذي جُزّئت به هذه البصمة.**
             *
             * فتدويرُ `AMIAL_DEVICE_HASH_KEY` لا يمحو هويّةَ الأجهزة:
             * الصفُّ القديم يُقارَن بمفتاحه، ويُرحَّل إلى الجديد عند أوّل
             * ظهورٍ للجهاز. وبدونه يكون التدويرُ **مسحاً لكلّ المقاعد**.
             */
            $table->unsignedSmallInteger('hash_key_version')->default(1);

            // آخرُ أربعة محارف — للعرض في الشاشة وحدَه، فيميّزها صاحبُها.
            $table->string('device_hint', 8)->nullable();

            $table->string('display_name', 120)->nullable();
            $table->string('platform', 32)->nullable();      // android · ios · web
            $table->string('app_version', 32)->nullable();

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();

            $table->boolean('is_active')->default(true);

            // بياناتٌ غيرُ حسّاسةٍ عند الحاجة (طرازٌ مثلاً) — ولا أسرار.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // ① **المقعدُ يُحسم في القاعدة** — انظر رأسَ الملفّ.
            $table->unique(['merchant_user_id', 'device_uuid_hash'], 'pos_device_merchant_uuid_unique');

            $table->index(['merchant_user_id', 'is_active', 'revoked_at'], 'pos_device_active_idx');
            $table->index('branch_id');

            $table->foreign('merchant_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_pos_devices');
    }
};
