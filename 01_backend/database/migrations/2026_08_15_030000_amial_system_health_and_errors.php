<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-OBSERVABILITY-001 — **أن تعرف أنّ شيئاً انكسر قبل أن يخبرك تاجر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** ثلاثةُ أعطالٍ في يومٍ واحدٍ وصلت عبر صاحب المشروع
 * لا عبر أيّ جهاز: الأرقامُ المتبادلة في دفتر الديون، وأربعون شاشةً
 * ميّتةً عند الضغط، وحلقةُ ٤١٩ التي تمنع الدخول.
 *
 * وقِيس أنّ ثلاثَ طبقاتٍ غائبةٌ من المنصّة: **لا نقطةَ صحّةٍ إطلاقاً**،
 * ولا تتبّعَ أخطاءٍ من جهة الخادم، ولا سجلَّ توفّر. **فصاحبُ المشروع هو
 * جهازُ الرصد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ جدولان لا خدمةٌ خارجيّة:** الخادمُ في اليمن، وربطُه بخدمةٍ
 * خارجيّة يجعل الرصدَ يسقط مع أوّل انقطاع — وهو الوقتُ الذي يُحتاج فيه.
 * والبياناتُ ماليّةٌ فلا تخرج.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── سجلُّ الأخطاء ────────────────────────────────────────────
        //
        // **يُبصم الخطأ لا يُكرَّر**: عطلٌ يقع ألفَ مرّةٍ سطرٌ واحدٌ بعدّاد،
        // لا ألفُ سطرٍ يُغرق الجدول ويُخفي ما عداه.
        Schema::create('system_errors', function (Blueprint $t) {
            $t->id();

            // بصمةٌ من الصنف + الملفّ + السطر — لا من الرسالة (فيها معرّفات).
            $t->string('fingerprint', 64)->unique();

            $t->string('exception', 191);
            $t->text('message');
            $t->string('file', 512)->nullable();
            $t->unsignedInteger('line')->nullable();

            $t->string('method', 10)->nullable();
            $t->string('path', 512)->nullable();
            $t->unsignedSmallInteger('status')->nullable();

            // من وقع له — للتقدير لا للاتّهام.
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('actor_type', 20)->nullable();

            $t->unsignedInteger('occurrences')->default(1);
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();

            // **الحالةُ تُقفل يدويّاً**: خطأٌ يختفي وحدَه لم يُصلَح — نام.
            $t->enum('status_flag', ['open', 'acknowledged', 'resolved'])
                ->default('open');
            $t->unsignedBigInteger('resolved_by_user_id')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->text('note')->nullable();

            // أثرٌ مختصَر — لا الكاملُ: فيه مسارات وأسرار.
            $t->text('trace_head')->nullable();

            $t->timestamps();

            $t->index(['status_flag', 'last_seen_at']);
            $t->index('occurrences');
        });

        // ── نبضُ التوفّر ─────────────────────────────────────────────
        //
        // **صفٌّ لكلّ فحص**، لا لكلّ طلب. ويُقرأ منه: كم مرّةً سقط النظام،
        // ومتى، وأيُّ قطعةٍ منه.
        Schema::create('system_health_checks', function (Blueprint $t) {
            $t->id();

            $t->string('component', 40);          // db · cache · queue · storage · disk
            $t->enum('state', ['up', 'degraded', 'down']);
            $t->unsignedInteger('latency_ms')->nullable();

            // **«غير معروف» ليس صفراً** (القاعدة السابعة): فحصٌ لم يجرِ
            // يُترك فارغاً ولا يُملأ بصفر.
            $t->text('detail')->nullable();

            $t->timestamp('checked_at');

            $t->index(['component', 'checked_at']);
            $t->index(['state', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health_checks');
        Schema::dropIfExists('system_errors');
    }
};
