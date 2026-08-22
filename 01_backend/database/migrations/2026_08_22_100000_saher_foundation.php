<?php

/**
 * SAHER-FOUNDATION-001 — **أساسُ ساهر: ذاكرةٌ للأدلّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس قبل أوّل جدول:**
 *
 * في المشروع **أربعةٌ وعشرون سكربتاً / ٥٦٠٠ سطر** تجمع أدلّةً حقيقيّةً
 * اليوم — `click-check` و`dom-refs` و`sweep-admin` و`axis-matrix` و
 * `press-every-button` و`load-test` و`pentest` و`rbac`. **وكلُّها تطبع
 * في طرفيّةٍ ثمّ يُرمى ما طبعت.**
 *
 * فلا تاريخَ ولا مقارنة: لا يُعرف أعطلٌ جديدٌ هو أم قديمٌ منذ شهر، ولا
 * متى ظهر، ولا هل أُصلح فعاد. **والجامعاتُ موجودة — الناقصُ ذاكرةٌ
 * وشاشة.** وذاك نمطُ «مبنيٌّ ولا يُوصَل إليه» واقعاً على أدوات المشروع
 * نفسِها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ قيودٍ في البناء لا في النيّة:**
 *
 * ① **ساهرٌ راصدٌ لا تابع.** لا جدولَ هنا يشير بمفتاحٍ أجنبيٍّ إلى جدول
 *    أعمال. فمفتاحٌ أجنبيٌّ يجعل حذفَ صفِّ أعمالٍ يفشل بسبب ساهر — أي
 *    يصير ساهر جزءاً من مسارٍ حيّ، وهو ما يمنعه §2.1.
 *
 * ② **«تعذّر الفحص» ليس «لا مشكلة».** لكلّ جولةِ فحصٍ حالةٌ صريحة، ولكلّ
 *    مصدرٍ صحّةٌ صريحة. وجولةٌ سقطت تُقرأ `FAILED` لا «صفرُ اكتشافات».
 *    (القاعدة السابعة.)
 *
 * ③ **لا اكتشافَ بلا دليل.** درجةُ الثقة عمودٌ إلزاميّ، و`PROVEN` بلا
 *    صفِّ دليلٍ واحدٍ يمنعه حارس.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── ① المصادر — وصحّةُ كلٍّ تُقال صراحةً ────────────────────────
        Schema::create('saher_sources', function (Blueprint $t) {
            $t->id();
            $t->string('code', 64)->unique();          // guards · routes · schema…
            $t->string('label_ar', 160);

            /**
             * HEALTHY · DEGRADED · UNAVAILABLE · STALE · NOT_CONFIGURED
             *
             * **ولا قيمةَ افتراضيّةٍ «سليم».** مصدرٌ لم يُشغَّل بعدُ حالتُه
             * `NOT_CONFIGURED` — فصحّةٌ مفترَضةٌ هي اختراعُ صحّة.
             */
            $t->string('health', 24)->default('NOT_CONFIGURED');
            $t->string('health_reason', 255)->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable();

            /** بعده تُعدّ النتيجةُ بائتة — بالدقائق، ولكلّ مصدرٍ إيقاعُه. */
            $t->unsignedInteger('stale_after_minutes')->default(1440);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });

        // ── ② جولاتُ الفحص ────────────────────────────────────────────
        Schema::create('saher_scan_runs', function (Blueprint $t) {
            $t->id();
            $t->string('source_code', 64)->index();
            $t->string('trigger', 24)->default('manual');   // manual · scheduled · deploy

            /** RUNNING · COMPLETED · FAILED · PARTIAL */
            $t->string('status', 24)->default('RUNNING');
            $t->text('failure_reason')->nullable();

            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();

            /** **تُقاس ولا تُقدَّر** — ومنها يُعرف أنّ مُطابِقاً عمي. */
            $t->unsignedInteger('assets_seen')->default(0);
            $t->unsignedInteger('findings_opened')->default(0);
            $t->unsignedInteger('findings_resolved')->default(0);

            $t->string('git_sha', 40)->nullable();
            $t->string('git_branch', 160)->nullable();
            $t->unsignedBigInteger('run_by_user_id')->nullable();

            $t->timestamps();
            $t->index(['source_code', 'started_at']);
        });

        // ── ③ الاكتشافات ─────────────────────────────────────────────
        Schema::create('saher_findings', function (Blueprint $t) {
            $t->id();

            /** رمزٌ يقرؤه إنسان: SAHER-GUARD-0074 */
            $t->string('reference', 40)->unique();

            /**
             * **البصمةُ تمنع ألفَ اكتشافٍ لعطلٍ واحد.**
             *
             * تُشتقّ من (القاعدة + الأصل + الرمز)، فالعطلُ نفسُه في الجولة
             * التالية **يُحدَّث لا يُستنسَخ** — وبه يُعرف «منذ متى» و«كم
             * مرّة».
             */
            $t->string('fingerprint', 64)->unique();

            $t->string('rule_id', 64)->index();
            $t->string('source_code', 64)->index();
            $t->string('category', 32)->index();   // security · financial · database…

            $t->string('title', 255);
            $t->text('expected_behavior')->nullable();
            $t->text('actual_behavior')->nullable();
            $t->text('impact')->nullable();
            $t->text('suggested_action')->nullable();

            /** CRITICAL · HIGH · MEDIUM · LOW · INFO */
            $t->string('severity', 16)->index();

            /** PROVEN · HIGH_CONFIDENCE · SUSPECTED · INFORMATIONAL */
            $t->string('confidence', 24)->index();

            /**
             * OPEN · ACKNOWLEDGED · INVESTIGATING · FIXED_PENDING_VERIFICATION
             * · RESOLVED · FALSE_POSITIVE · ACCEPTED_RISK · SUPPRESSED · REOPENED
             *
             * **و`RESOLVED` لا تُكتب بيد.** تُكتب حين تجري جولةٌ فلا تجد
             * السبب — فإعلانُ إصلاحٍ بلا إعادة فحصٍ ادّعاء.
             */
            $t->string('status', 32)->default('OPEN')->index();

            $t->string('asset_type', 40)->nullable();   // route · file · table · screen
            $t->string('asset_key', 255)->nullable();   // اسمُ المسار أو مسارُ الملفّ
            $t->string('file_path', 255)->nullable();
            $t->unsignedInteger('line_start')->nullable();
            $t->unsignedInteger('line_end')->nullable();
            $t->string('symbol', 160)->nullable();      // الصنف::الدالّة

            $t->timestamp('first_seen_at');
            $t->timestamp('last_seen_at');
            $t->unsignedInteger('occurrence_count')->default(1);

            /** آخرُ جولةٍ رأته — وبها يُعرف البائتُ من الحيّ. */
            $t->unsignedBigInteger('last_scan_run_id')->nullable();
            $t->timestamp('resolved_at')->nullable();

            /** درجةُ خطرٍ محسوبةٌ لا مكتوبة — تُرتَّب بها الشاشة. */
            $t->unsignedInteger('risk_score')->default(0)->index();

            $t->timestamps();
            $t->index(['status', 'severity']);
            $t->index(['category', 'status']);
        });

        // ── ④ الأدلّة — ولا `PROVEN` بلا صفٍّ هنا ──────────────────────
        Schema::create('saher_finding_evidence', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('finding_id')->index();

            /** ROUTE_MIDDLEWARE · CODE_LINE · DB_ROW · COMMAND_OUTPUT · QUERY_RESULT */
            $t->string('kind', 40);
            $t->string('label_ar', 200);

            /**
             * **الدليلُ نصٌّ مقتطَعٌ لا مرجعٌ إلى مكانٍ يتغيّر.** ملفٌّ
             * يُعدَّل بعد الاكتشاف يجعل «السطر ٤٢» يشير إلى شيءٍ آخر،
             * فيُحفظ ما قِيس وقتَ القياس.
             */
            $t->text('body');
            $t->string('collected_by', 120)->nullable();  // الأمرُ أو الدالّة
            $t->timestamp('collected_at');
            $t->timestamps();
        });

        // ── ⑤ تاريخُ كلّ اكتشاف ───────────────────────────────────────
        Schema::create('saher_finding_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('finding_id')->index();
            $t->string('event', 40);                  // OPENED · REOPENED · STATUS_CHANGED…
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32)->nullable();
            $t->text('note')->nullable();

            /** **من فعل** — ولا يُقبل مجهولٌ في فعلٍ بشريّ. */
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_type', 24)->default('system');   // system · scan · admin
            $t->timestamp('occurred_at');
            $t->timestamps();
        });

        // ── ⑥ الكتم — وله سببٌ ومدّة، ولا يُكتم بلا هذين ───────────────
        Schema::create('saher_suppressions', function (Blueprint $t) {
            $t->id();
            $t->string('fingerprint', 64)->index();
            $t->text('reason');                       // **إلزاميّ** — لا كتمَ صامت

            /**
             * **وكتمٌ بلا نهايةٍ صمتٌ مؤجَّل.** (وهو الدرسُ نفسُه المكتوبُ
             * في إقرار حدّ Gradle: «إقرارٌ لا ينتهي صمتٌ مؤجَّل».)
             */
            $t->timestamp('expires_at')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });

        // ── ⑦ خطُّ الأساس — يفرّق الدَّينَ القديم عن الانحدار الجديد ────
        Schema::create('saher_baselines', function (Blueprint $t) {
            $t->id();
            $t->string('label', 160);
            $t->string('git_sha', 40)->nullable();
            $t->unsignedInteger('finding_count')->default(0);
            $t->json('fingerprints')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'saher_baselines', 'saher_suppressions', 'saher_finding_events',
            'saher_finding_evidence', 'saher_findings', 'saher_scan_runs',
            'saher_sources',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
