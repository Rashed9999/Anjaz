<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-OTP-CENTER-001 — أرقامُ العرض تنتقل من متغيّر بيئةٍ إلى جدول.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا لا تبقى في `AMIAL_DEMO_PHONES`؟**
 *
 * لأنّ فتح حسابِ عرضٍ أو إقفاله يصير **نشراً كاملاً** — ست دقائق بناء
 * وإعادة تشغيل. ويومَ الإطلاق تحتاج أن تُقفل الباب في ثانية، لا أن
 * تنتظر بناءً وأنت تنظر إلى تسجيلاتٍ تمرّ.
 *
 * وأخطرُ من البطء: **ما يُضبط في البيئة لا يُدقَّق**. لا يُعرف من أضافه
 * ولا متى، ولا يظهر في أيّ شاشة. ورقمٌ بقي في القائمة سهواً بابٌ مفتوح.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ويبقى المتغيّر مقروءاً — بذرةً أوّليّةً لا حكماً.**
 *
 * فالخوادمُ القائمة تعمل اليوم بقيمته، وتفريغُه فجأةً يُقفل حسابات العرض
 * على من يستعملها. فالجدولُ إن كان فارغاً يُقرأ المتغيّر، وإن امتلأ فهو
 * وحده الحكم. ويحرس هذا `OtpDemoNumbersTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_demo_numbers', function (Blueprint $t) {
            $t->id();

            // موحَّدٌ إلى أرقامٍ فقط — فالرقم يصل بأربعة أشكال.
            $t->string('phone', 24)->unique();

            $t->string('label', 120)->nullable();        // «عميل تجريبيّ ١»

            /**
             * **يُعطَّل ولا يُحذف.** فحذفُ رقمٍ يمحو أثرَ من أضافه ومتى،
             * وسجلُّ من فتح باباً في نظامٍ ماليٍّ لا يُمحى.
             */
            $t->boolean('is_active')->default(true)->index();

            $t->unsignedBigInteger('added_by_user_id')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->unsignedInteger('use_count')->default(0);

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_demo_numbers');
    }
};
