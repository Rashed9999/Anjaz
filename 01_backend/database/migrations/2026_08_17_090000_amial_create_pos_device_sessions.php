<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-POS-DEVICES-003 — **الرمزُ مربوطٌ بمقعده، لا طليقاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطلُ الذي يمنعه هذا الجدول:**
 *
 * جهازٌ يُسجَّل فيشغل مقعداً، ثمّ **يُنسخ رمزُه إلى جهازٍ آخر** — فيعمل
 * الثاني بلا مقعد. والحدُّ قائمٌ في جدول الأجهزة، **والالتفافُ عليه لا
 * يمرّ من بابه أصلاً**: لا يُسجَّل جهازٌ ثانٍ، بل يُستعمل رمزُ الأوّل.
 *
 * فما لم يُربط الرمزُ بمقعده، كان `max_pos_devices` **حدَّ تسجيلٍ لا حدَّ
 * استعمال** — تُشترى باقةُ جهازٍ واحدٍ ويعمل عشرة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ قراراتٍ تُقرأ ولا تُخمَّن:**
 *
 * ① **الربطُ في الخادم لا في الترويسة.** ترويسةُ الجهاز تأتي من التطبيق
 *    فتُزوَّر أو تُحذف. **فمصدرُ الحقيقة هذا الصفّ**: الرمزُ يعرف مقعدَه
 *    ولو لم يقل حاملُه شيئاً، وحذفُ الترويسة لا يُحرّر الرمز.
 *    والترويسةُ تُقارَن ولا يُوثَق بها وحدَها (القيدُ السادس).
 *
 * ② **`access_token_id` مفتاحٌ فريد.** رمزٌ واحدٌ لمقعدٍ واحد — ولا
 *    يُعاد ربطُه بمقعدٍ ثانٍ بطلبٍ لاحق.
 *
 * ③ **لا يُحذف الصفُّ عند الخروج.** يُختم `ended_at` — فيبقى جوابُ
 *    «أيُّ جهازٍ نفّذ عمليّةَ أمس» بعد انتهاء الجلسة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_device_sessions')) {
            return;
        }

        Schema::create('pos_device_sessions', function (Blueprint $table) {
            $table->id();

            // معرّفُ رمز Passport — نصٌّ لا رقم.
            $table->string('access_token_id', 100)->unique();

            $table->unsignedBigInteger('pos_device_id');
            $table->unsignedBigInteger('merchant_user_id');

            // **الموظّفُ يُسجَّل ولا يُملَك الجهازُ به** — للتدقيق وحدَه:
            // «مَن كان على هذا المقعد حين وقعت العمليّة».
            $table->unsignedBigInteger('actor_user_id');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            $table->index(['pos_device_id', 'ended_at'], 'pos_session_device_idx');
            $table->index(['merchant_user_id', 'ended_at'], 'pos_session_merchant_idx');

            $table->foreign('pos_device_id')->references('id')
                ->on('merchant_pos_devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_device_sessions');
    }
};
