<?php

namespace App\Saher\Findings;

use Illuminate\Support\Facades\DB;

/**
 * SAHER-FOUNDATION-005 — **مخزنُ الاكتشافات ودورةُ حياتها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ قراراتٍ هنا، ولكلٍّ ثمنٌ دُفع في هذا المشروع:**
 *
 * ① **لا يُكتب `PROVEN` بلا دليل.** يُخفَّض إلى `HIGH_CONFIDENCE` ويُسجَّل
 *    السبب. فادّعاءُ إثباتٍ بلا برهان هو «حارسٌ يكذب»، وهو أسوأ من
 *    غيابه: يُرسل من يصدّقه خلف عطلٍ أو يُطمئنه على عطلٍ قائم.
 *
 * ② **`RESOLVED` تُكتب بجولةٍ لا بيد.** ما لم ترَه الجولةُ الأخيرةُ
 *    يُغلَق آليّاً — وما رآه يبقى مفتوحاً مهما ادّعى أحد. فزرُّ «تمّ
 *    الإصلاح» بلا إعادة فحصٍ يُنتج لوحةً خضراءَ فوق عطلٍ حيّ.
 *
 * ③ **وما عاد يُفتح `REOPENED` لا `OPEN`.** الفرقُ ليس تسمية: عطلٌ
 *    أُصلح ثمّ عاد يدلّ على إصلاحٍ ناقصٍ أو انحدار، وهو أولى بالنظر من
 *    عطلٍ جديد.
 */
class FindingStore
{
    /** حالاتٌ يقرّرها إنسانٌ — لا تُلمس بجولةِ فحص. */
    private const HUMAN_HELD = ['FALSE_POSITIVE', 'ACCEPTED_RISK', 'SUPPRESSED'];

    /** أسماءُ المصادر بالعربيّة — للصفّ الذي يُستحدَث ضمناً. */
    private const LABELS = [
        'guards' => 'جرد الحرّاس والأسطح غير المحروسة',
        'routes' => 'المسارات وحرّاسها',
        'gate' => 'تغطية بوّابة الفحص',
        'data_truth' => 'صدقُ البيانات — قيمٌ بلا مخرج ودوالُّ بلا مُنادٍ',
    ];

    /**
     * يبدأ جولةً ويُرجع معرِّفَها.
     *
     * **وتُفتح بحالة `RUNNING` لا `COMPLETED`** — فجولةٌ تنهار في منتصفها
     * تبقى ظاهرةً بحالتها، ولا تُقرأ نجاحاً بالصمت.
     */
    public function beginRun(string $sourceCode, string $trigger = 'manual', ?int $byUserId = null): int
    {
        $now = now();

        $this->ensureSource($sourceCode);

        DB::table('saher_sources')->where('code', $sourceCode)
            ->update(['last_attempt_at' => $now, 'updated_at' => $now]);

        return (int) DB::table('saher_scan_runs')->insertGetId([
            'source_code' => $sourceCode,
            'trigger' => $trigger,
            'status' => 'RUNNING',
            'started_at' => $now,
            'run_by_user_id' => $byUserId,
            'git_sha' => $this->gitSha(),
            'git_branch' => $this->gitBranch(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * يكتب اكتشافات الجولة ويُغلق ما لم تعد ترَه.
     *
     * @param  list<Finding>  $findings
     * @param  int  $assetsSeen  كم أصلاً فحصت الجولةُ فعلاً — **يُقاس**
     * @return array{opened:int, reopened:int, updated:int, resolved:int}
     */
    public function commitRun(int $runId, string $sourceCode, array $findings, int $assetsSeen): array
    {
        $now = now();
        $counts = ['opened' => 0, 'reopened' => 0, 'updated' => 0, 'resolved' => 0];
        $seen = [];

        foreach ($findings as $finding) {
            $fp = $finding->fingerprint();
            $seen[] = $fp;

            $existing = DB::table('saher_findings')->where('fingerprint', $fp)->first();

            $confidence = $this->honestConfidence($finding);

            if ($existing === null) {
                $counts['opened']++;
                $this->insert($runId, $finding, $fp, $confidence, $now);

                continue;
            }

            // **حالةٌ قرّرها إنسانٌ لا تُنقض بجولة** — لكنّ العدَّ والمشاهدة
            // يُحدَّثان: كتمٌ لا يعني أنّ العطلَ اختفى.
            $reopened = ! in_array($existing->status, self::HUMAN_HELD, true)
                && in_array($existing->status, ['RESOLVED', 'FIXED_PENDING_VERIFICATION'], true);

            DB::table('saher_findings')->where('id', $existing->id)->update([
                'last_seen_at' => $now,
                'occurrence_count' => $existing->occurrence_count + 1,
                'last_scan_run_id' => $runId,
                'severity' => $finding->severity,
                'confidence' => $confidence,
                'title' => $finding->title,
                'actual_behavior' => $finding->actual,
                'risk_score' => $this->risk($finding->severity, $confidence),
                'status' => $reopened ? 'REOPENED' : $existing->status,
                'resolved_at' => $reopened ? null : $existing->resolved_at,
                'updated_at' => $now,
            ]);

            $this->replaceEvidence((int) $existing->id, $finding, $now);

            if ($reopened) {
                $counts['reopened']++;
                $this->event((int) $existing->id, 'REOPENED', $existing->status, 'REOPENED',
                    'رآه فحصٌ بعد إغلاقه — إصلاحٌ ناقصٌ أو انحدار', $now);
            } else {
                $counts['updated']++;
            }
        }

        // ── ما لم ترَه الجولةُ يُغلَق — **بجولةٍ لا بيد** ─────────────
        $stale = DB::table('saher_findings')
            ->where('source_code', $sourceCode)
            ->whereNotIn('status', array_merge(self::HUMAN_HELD, ['RESOLVED']))
            ->when($seen !== [], fn ($q) => $q->whereNotIn('fingerprint', $seen))
            ->get(['id', 'status']);

        foreach ($stale as $row) {
            DB::table('saher_findings')->where('id', $row->id)->update([
                'status' => 'RESOLVED', 'resolved_at' => $now,
                'last_scan_run_id' => $runId, 'updated_at' => $now,
            ]);

            $this->event((int) $row->id, 'RESOLVED_BY_SCAN', $row->status, 'RESOLVED',
                "لم تعد جولةُ الفحص #{$runId} تجد السبب", $now);

            $counts['resolved']++;
        }

        $started = DB::table('saher_scan_runs')->where('id', $runId)->value('started_at');

        DB::table('saher_scan_runs')->where('id', $runId)->update([
            'status' => 'COMPLETED',
            'finished_at' => $now,
            // **والمدّةُ لا تكون سالبة.** أوّلُ جولةٍ حقيقيّة أخرجت
            // `duration_ms = -496` فسقط الإدراج: `diffInMilliseconds`
            // مُوقَّعةٌ وترتيبُ طرفيها يقلب الإشارة. والعمودُ غيرُ مُوقَّع،
            // فالقاعدةُ ردّته — **وردٌّ خيرٌ من رقمٍ سالبٍ يُخزَّن ويُعرَض**.
            'duration_ms' => $started
                ? max(0, (int) \Illuminate\Support\Carbon::parse($started)->diffInMilliseconds(now()))
                : null,
            'assets_seen' => $assetsSeen,
            'findings_opened' => $counts['opened'] + $counts['reopened'],
            'findings_resolved' => $counts['resolved'],
            'updated_at' => $now,
        ]);

        DB::table('saher_sources')->where('code', $sourceCode)->update([
            'health' => 'HEALTHY', 'health_reason' => null,
            'last_success_at' => $now, 'updated_at' => $now,
        ]);

        return $counts;
    }

    /**
     * **جولةٌ سقطت تُقال ساقطة** — ولا تُترَك `RUNNING` إلى الأبد ولا
     * تُقرأ «صفرُ اكتشافات». (القاعدة السابعة: غير معروف ≠ صفر.)
     *
     * **ولا يُغلَق شيءٌ عند السقوط**: اكتشافاتُ الجولة السابقة تبقى
     * مفتوحةً — فجامعٌ عطبَ لا يُثبت أنّ العطلَ زال.
     */
    public function failRun(int $runId, string $sourceCode, string $reason): void
    {
        $now = now();

        $this->ensureSource($sourceCode);

        DB::table('saher_scan_runs')->where('id', $runId)->update([
            'status' => 'FAILED', 'failure_reason' => mb_substr($reason, 0, 2000),
            'finished_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('saher_sources')->where('code', $sourceCode)->update([
            'health' => 'UNAVAILABLE',
            'health_reason' => mb_substr($reason, 0, 250),
            'updated_at' => $now,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════

    /**
     * **صفُّ المصدر يُضمَن، ولا يُفترَض موجوداً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **العطل الذي أدخل هذا، وقد أمسكته البوّابة لا القراءة:**
     *
     * صفوفُ المصادر كانت مبذورةً في الهجرة وحدَها، فسقط مِقياسان في
     * المجموعة الكاملة ومرّا منفردين: `saher_sources` **فارغ**. وأحدُهما
     * لم يسقط بل خرج «مخاطِراً» (لا توكيدَ واحد) — لأنّ حلقتَه دارت على
     * لا شيء. **ومِقياسٌ يدور على جدولٍ فارغٍ يخرج أخضرَ بلا أن يفحص.**
     *
     * **والدرسُ أعمقُ من الاختبار:** غيابُ صفِّ المصدر لا يُقرأ
     * `UNAVAILABLE` — يُقرأ **عدماً**. فالشاشةُ لا تعرض المصدرَ إطلاقاً،
     * ولا يُعلَم أنّ راصداً كان يُفترض أن يعمل. **وذاك أسوأ من حالةٍ
     * حمراء**: الحمراءُ تُرى، والغيابُ لا يُرى. (القاعدة السابعة.)
     *
     * فالضمانُ هنا لا في البذرة: الشيفرةُ التي تحتاج الصفَّ تُنشئه.
     */
    private function ensureSource(string $code): void
    {
        if (DB::table('saher_sources')->where('code', $code)->exists()) {
            return;
        }

        $now = now();

        // **ويولد `NOT_CONFIGURED` لا `HEALTHY`** — صفٌّ يُستحدَث لأنّ
        // جولةً بدأت لم ينجح فيها شيءٌ بعد، والجولةُ نفسُها تكتب مصيرَه.
        DB::table('saher_sources')->insert([
            'code' => $code,
            'label_ar' => self::LABELS[$code] ?? $code,
            'health' => 'NOT_CONFIGURED',
            'health_reason' => 'لم تكتمل جولةُ فحصٍ ناجحةٌ بعد',
            'stale_after_minutes' => 1440,
            'is_enabled' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * **`PROVEN` بلا دليلٍ تُخفَّض.**
     *
     * ولا يُرمى استثناءٌ: رميُه يُسقط الجولةَ كلَّها بسبب اكتشافٍ واحدٍ
     * سيّئِ الصياغة — فيُفقَد ما جُمع صحيحاً. وهو الدرسُ نفسُه من
     * `AuditPayloadKeysGuardTest`: عقوبةٌ على خطأٍ صغيرٍ بفقدِ الأثر كلِّه.
     */
    private function honestConfidence(Finding $f): string
    {
        if ($f->confidence === 'PROVEN' && ! $f->hasProof()) {
            return 'HIGH_CONFIDENCE';
        }

        return $f->confidence;
    }

    private function insert(int $runId, Finding $f, string $fp, string $confidence, $now): void
    {
        $id = (int) DB::table('saher_findings')->insertGetId([
            'reference' => $this->nextReference($f->category),
            'fingerprint' => $fp,
            'rule_id' => $f->ruleId,
            'source_code' => $f->sourceCode,
            'category' => $f->category,
            'title' => $f->title,
            'expected_behavior' => $f->expected,
            'actual_behavior' => $f->actual,
            'impact' => $f->impact,
            'suggested_action' => $f->suggestedAction,
            'severity' => $f->severity,
            'confidence' => $confidence,
            'status' => 'OPEN',
            'asset_type' => $f->assetType,
            'asset_key' => mb_substr($f->assetKey, 0, 255),
            'file_path' => $f->filePath,
            'line_start' => $f->lineStart,
            'line_end' => $f->lineEnd,
            'symbol' => $f->symbol,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'occurrence_count' => 1,
            'last_scan_run_id' => $runId,
            'risk_score' => $this->risk($f->severity, $confidence),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->replaceEvidence($id, $f, $now);
        $this->event($id, 'OPENED', null, 'OPEN', null, $now);
    }

    private function replaceEvidence(int $findingId, Finding $f, $now): void
    {
        // **الدليلُ يُستبدل لا يُراكَم**: عشرُ جولاتٍ تُنتج عشرَ نسخٍ من
        // الدليل نفسِه، فتُثقل الصفحةَ ولا تُضيف معنىً. والتاريخُ في
        // `saher_finding_events` لا هنا.
        DB::table('saher_finding_evidence')->where('finding_id', $findingId)->delete();

        foreach ($f->evidence as $e) {
            DB::table('saher_finding_evidence')->insert([
                'finding_id' => $findingId,
                'kind' => $e->kind,
                'label_ar' => mb_substr($e->label, 0, 200),
                'body' => mb_substr($e->body, 0, 8000),
                'collected_by' => $e->collectedBy,
                'collected_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function event(int $findingId, string $event, ?string $from, ?string $to,
        ?string $note, $now, ?int $actorUserId = null): void
    {
        DB::table('saher_finding_events')->insert([
            'finding_id' => $findingId,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorUserId === null ? 'scan' : 'admin',
            'occurred_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * **درجةُ الخطر تُحسب ولا تُكتب.**
     *
     * وتضرب الثقةَ في الأثر: عطلٌ حرجٌ مشكوكٌ فيه لا يعلو على عطلٍ عالٍ
     * مثبَت. فترتيبُ الشاشة يضع ما يُتصرَّف فيه أوّلاً، لا ما يخيف أكثر.
     */
    private function risk(string $severity, string $confidence): int
    {
        $impact = ['CRITICAL' => 100, 'HIGH' => 70, 'MEDIUM' => 40, 'LOW' => 15, 'INFO' => 5];
        $trust = ['PROVEN' => 100, 'HIGH_CONFIDENCE' => 75, 'SUSPECTED' => 40, 'INFORMATIONAL' => 10];

        return (int) round(($impact[$severity] ?? 5) * ($trust[$confidence] ?? 10) / 100);
    }

    private function nextReference(string $category): string
    {
        $prefix = 'SAHER-' . strtoupper(substr(preg_replace('~[^a-z]~i', '', $category) ?: 'GEN', 0, 5));

        $n = DB::table('saher_findings')->where('reference', 'like', $prefix . '-%')->count() + 1;

        return sprintf('%s-%04d', $prefix, $n);
    }

    private function gitSha(): ?string
    {
        $head = base_path('.git/HEAD');

        if (! is_readable($head)) {
            return null;
        }

        $ref = trim((string) file_get_contents($head));

        if (str_starts_with($ref, 'ref: ')) {
            $path = base_path('.git/' . substr($ref, 5));

            return is_readable($path) ? substr(trim((string) file_get_contents($path)), 0, 40) : null;
        }

        return substr($ref, 0, 40) ?: null;
    }

    private function gitBranch(): ?string
    {
        $head = base_path('.git/HEAD');

        if (! is_readable($head)) {
            return null;
        }

        $ref = trim((string) file_get_contents($head));

        return str_starts_with($ref, 'ref: refs/heads/')
            ? substr($ref, strlen('ref: refs/heads/')) : null;
    }
}
