<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-ENTITLEMENTS-001 — **الباقاتُ تُحرَّر من اللوحة لا من الشيفرة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * `CapabilityRegistry` يحمل **الافتراضيّ**، وهذان الجدولان يحملان
 * **القرار**:
 *
 * | الجدول | يجيب |
 * |---|---|
 * | `plan_capabilities` | ماذا تفتح باقةُ «الأعمال» **لكلّ التجّار**؟ |
 * | `merchant_capability_overrides` | وماذا فُتح **لهذا التاجر وحده**؟ |
 *
 * ولماذا جدولان: تجربةُ تسعيرٍ تمسّ الباقة، ومنحةٌ لتاجرٍ بعينه (تعويضٌ
 * أو تجربةٌ مجّانيّة) تمسّه وحدَه. **وخلطُهما يجعل تعديلَ الباقة يمحو
 * منحةً فرديّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والفراغُ ليس منعاً** (القاعدة ٧): صفٌّ غيرُ موجودٍ يعني «لم يُقرَّر —
 * فليُعمَل بافتراضيّ السجلّ». والمنعُ الصريحُ صفٌّ بـ`is_enabled=false`.
 *
 * ولو كان الغيابُ منعاً لاحتاج كلُّ نشرةٍ جديدةٍ إلى ٥ × ٥٩ صفّاً قبل أن
 * يعمل شيء — **ولانطفأت قدرةٌ جديدةٌ لحظةَ ولادتها**.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── قرارُ المنصّة: باقة × قدرة ─────────────────────────────────
        Schema::create('plan_capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 32);
            $table->string('capability_code', 64);

            $table->boolean('is_enabled');

            // **لماذا غُيّر** — وتجربةُ تسعيرٍ بلا سببٍ مكتوبٍ لا تُراجَع
            // بعد شهرين، ولا يُعرف أتُعاد أم تُثبَّت.
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->unique(['plan', 'capability_code']);
            $table->index('capability_code');
        });

        // ── قرارٌ لتاجرٍ بعينه ─────────────────────────────────────────
        Schema::create('merchant_capability_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->string('capability_code', 64);

            // grant: افتح رغم الباقة · revoke: أغلق رغمها
            $table->enum('effect', ['grant', 'revoke']);

            // **منحةٌ بلا أجلٍ تصير باقةً دائمةً مجّانيّة.** فتجربةُ
            // أسبوعين تنتهي وحدَها، ومن أراد الدوام تركه فارغاً عامداً.
            $table->timestamp('expires_at')->nullable();

            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();

            $table->unique(['merchant_user_id', 'capability_code'], 'mco_merchant_cap_unique');
            $table->index(['capability_code', 'effect']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_capability_overrides');
        Schema::dropIfExists('plan_capabilities');
    }
};
