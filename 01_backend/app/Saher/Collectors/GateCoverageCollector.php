<?php

namespace App\Saher\Collectors;

use App\Saher\Findings\Evidence;
use App\Saher\Findings\Finding;
use App\Saher\Support\GitTree;
use Illuminate\Support\Facades\DB;

/**
 * SAHER-GATE-004 — **التزامٌ لم يمرّ ببوّابة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس في جلسةٍ واحدة:** ستّةُ التزاماتٍ دُفعت إلى فرع النشر بلا
 * فحص. وكلُّ دمجٍ لها كشف عطلاً — أخطرُها استنتاجٌ معكوس في مفسِّر بصمة
 * التدقيق: **حقلٌ مُلئ بعد البصم كان يُسمّى «أُفرغ»**، أي أنّ سجلَّ
 * التدقيق يُفسّر التزويرَ فقدَ بياناتٍ بريئاً.
 *
 * **ولم يُكتشف واحدٌ منها إلّا بعد ساعةٍ من دفعه.** والاكتشافُ المتأخّر
 * على فرعٍ يُبنى منه الإنتاج ليس اكتشافاً — هو حظّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ حدودٍ تُقال ولا تُسكَت عنها:**
 *
 * ① **الإيصالُ محلّيٌّ.** يُكتب من `verify.sh` على هذه الآلة. فمن فحص
 *    على آلةٍ أخرى — أو في CI — لا إيصالَ له هنا. ولذلك درجةُ الثقة
 *    `SUSPECTED` لا `PROVEN`: **الغيابُ لا يُثبت أنّه لم يُفحَص**، يُثبت
 *    أنّنا **لا نملك دليلاً** أنّه فُحص. والفرقُ هو القاعدةُ السابعة.
 *
 * ② **ولا يُبلَّغ عمّا قبل أوّل إيصال.** الجدولُ يبدأ فارغاً، فالتاريخُ
 *    كلُّه بلا إيصالات — والإبلاغُ عنه يُخرج مئاتِ الاكتشافات في أوّل
 *    جولة، فيُعوّد القارئَ التجاهلَ يومَ تصدق. (وهو الدرسُ نفسُه من
 *    لافتة «عُبث بالسجلّ — ٤٢ موضعاً».)
 *
 * ③ **والشهادةُ للشجرة لا للاسم.** فتعديلُ رسالةٍ أو إعادةُ أساسٍ لا
 *    تُبطل شهادةً على محتوىً لم يتغيّر.
 */
class GateCoverageCollector
{
    public const SOURCE = 'gate';

    /** كم التزاماً يُنظَر فيه — والأقدمُ منها تاريخٌ لا يُراجَع. */
    private const WINDOW = 40;

    /** @return array{findings:list<Finding>, assets_seen:int} */
    public function collect(): array
    {
        $commits = GitTree::recentCommits(self::WINDOW);

        if ($commits === []) {
            // ══════════════════════════════════════════════════════════
            // SAHER-GATE-005 — **«لا تاريخَ هنا» غيرُ «جولةٍ عمياء».**
            //
            // قِيس على الإنتاج: `.dockerignore` يستثني `.git` (سطر ٤)،
            // فالحاويةُ بلا تاريخِ git إطلاقاً. و`git log` يخرج فارغاً،
            // **فيُقرأ ذلك «جولةٌ عمياء» ويُرفع بلافتةٍ حمراء في كلّ
            // ضغطةٍ على «شغّل فحصاً الآن»** — إلى الأبد، ولا شيءَ
            // مكسور.
            //
            // **ولافتةٌ حمراءُ دائمةٌ عن حالةٍ سليمةٍ تُعوّد القارئَ
            // تجاهلَ اللافتة يومَ تصدق** — وهو الدرسُ المكتوب في هذا
            // المشروع مرّتين (سلسلةُ التدقيق «المكسورة»، وساعةُ الخادم
            // «المتأخّرة»).
            //
            // فيُقال السببُ بعينه ويُميَّز عن العطل. (القاعدة السابعة:
            // الغيابُ يُقال صراحةً مع سببه ولا يُملأ بصفر.)
            // ══════════════════════════════════════════════════════════
            return [
                'findings' => [],
                'assets_seen' => 0,
                'unavailable' => 'لا تاريخَ git في هذه البيئة — '
                    . 'صورةُ النشر تُبنى بلا مجلّد `.git`، وهذا المصدرُ '
                    . 'يقرأ الالتزامات. يعمل على آلة التطوير وفي البوّابة.',
            ];
        }

        // ── حدُّ التاريخ: أوّلُ إيصالٍ سُجّل إطلاقاً ──────────────────
        $firstReceiptAt = DB::table('saher_gate_receipts')->min('ran_at');

        if ($firstReceiptAt === null) {
            // **لا إيصالَ واحداً بعد.** فالمصدرُ يعمل ولا حكمَ له —
            // والإبلاغُ عن كلّ التزامٍ في التاريخ ضجيجٌ لا رصد.
            return ['findings' => [], 'assets_seen' => count($commits)];
        }

        $receipts = DB::table('saher_gate_receipts')
            ->select('tree_sha', 'verdict', 'is_full', 'ran_at', 'checks_failed')
            ->orderByDesc('ran_at')->get()->groupBy('tree_sha');

        $findings = [];

        // **والعدُّ لِما قُرئ لا لِما حُوكم.**
        //
        // أوّلُ تشغيلٍ صادقٍ أخرج «جولةً عمياء» كاذبة: كلُّ الالتزامات
        // تسبق أوّلَ إيصال، فصار المحكومُ عليه صفراً فقُرئ ذلك عمىً.
        // **والفرقُ بين «لم أقرأ شيئاً» و«قرأتُ ولم أجد ما أحكم عليه»
        // هو القاعدةُ السابعة نفسُها** — واقعةً على الجامع لا على المُقاس.
        $seen = count($commits);

        foreach ($commits as $c) {
            // ما سبق أوّلَ إيصالٍ تاريخٌ لا يُحاكَم.
            if (strtotime($c['at']) < strtotime((string) $firstReceiptAt)) {
                continue;
            }

            $forTree = $receipts->get($c['tree']);

            if ($forTree === null) {
                $findings[] = $this->noReceipt($c);

                continue;
            }

            $pass = $forTree->firstWhere('verdict', 'PASS');

            if ($pass === null) {
                $findings[] = $this->onlyFailingReceipt($c, $forTree->first());

                continue;
            }

            if (! $pass->is_full) {
                $findings[] = $this->fastOnly($c);
            }
        }

        return ['findings' => $findings, 'assets_seen' => $seen];
    }

    // ══════════════════════════════════════════════════════════════════

    private function noReceipt(array $c): Finding
    {
        return (new Finding(
            ruleId: 'SAHER.GATE.COMMIT_WITHOUT_RECEIPT',
            sourceCode: self::SOURCE,
            category: 'operations',
            title: 'التزامٌ لا إيصالَ بوّابةٍ لمحتواه',
            severity: 'MEDIUM',

            // **والثقةُ ليست «مثبَتاً».** غيابُ الإيصال يُثبت أنّنا لا
            // نملك دليلاً على الفحص — لا أنّ الفحصَ لم يقع. فقد يكون
            // شُغّل على آلةٍ أخرى.
            confidence: 'SUSPECTED',
            assetKey: $c['sha'],
            assetType: 'commit',
            expected: 'كلُّ التزامٍ يُدفَع إلى فرع النشر مرّ بـ`verify.sh` '
                . 'بصفر فشل، فسُجّل إيصالٌ لشجرة محتواه',
            actual: 'لا إيصالَ لشجرة هذا الالتزام (' . substr($c['tree'], 0, 12) . ')',
            impact: 'شيفرةٌ قد تكون بلغت فرعَ النشر بلا فحص. وستُّ حالاتٍ '
                . 'كهذه في جلسةٍ واحدةٍ حملت أعطالاً حقيقيّة، منها استنتاجٌ '
                . 'معكوس في مفسِّر بصمة سجلّ التدقيق.',
            suggestedAction: 'يُشغَّل `bash scripts/verify.sh` على محتوى هذا '
                . 'الالتزام. وإن فُحص على آلةٍ أخرى فالإيصالُ ناقصٌ لا الفحص — '
                . 'ويُوحَّد موضعُ التسجيل.',
            symbol: $c['author'],
        ))->withEvidence(
            new Evidence(
                'COMMAND_OUTPUT',
                'الالتزام وشجرتُه',
                "sha:     {$c['sha']}\ntree:    {$c['tree']}\n"
                    . "author:  {$c['author']}\nwhen:    {$c['at']}\n"
                    . "subject: {$c['subject']}",
                'git log --format=%H%x09%T…',
            ),
            Evidence::absence(
                'ما بُحث عنه ولم يوجد',
                "صفٌّ في `saher_gate_receipts` حيث tree_sha = {$c['tree']}",
                'GateCoverageCollector',
            ),
        );
    }

    private function onlyFailingReceipt(array $c, $receipt): Finding
    {
        return (new Finding(
            ruleId: 'SAHER.GATE.COMMIT_ON_FAILING_GATE',
            sourceCode: self::SOURCE,
            category: 'operations',
            title: 'التزامٌ محتواه فُحص فسقط، ولا إيصالَ نجاحٍ بعده',

            // **وهذا أشدُّ من الغياب**: هناك دليلٌ إيجابيٌّ على السقوط.
            severity: 'HIGH',
            confidence: 'PROVEN',
            assetKey: $c['sha'],
            assetType: 'commit',
            expected: 'لا يُلتزَم قبل أن يصير الفشل صفراً',
            actual: 'آخرُ إيصالٍ لهذه الشجرة حكمُه FAIL بـ'
                . (int) $receipt->checks_failed . ' فحصٍ ساقط',
            impact: 'الشيفرةُ ملتزَمةٌ ومحتواها مفحوصٌ وساقط — والقاعدةُ '
                . 'الأولى في المشروع صريحة.',
            suggestedAction: 'يُصلَح الساقطُ ثمّ تُعاد البوّابةُ على المحتوى نفسِه',
            symbol: $c['author'],
        ))->withEvidence(new Evidence(
            'DB_ROW',
            'إيصالُ البوّابة الساقط',
            "tree:     {$c['tree']}\nverdict:  {$receipt->verdict}\n"
                . "failed:   {$receipt->checks_failed}\nran_at:   {$receipt->ran_at}",
            'saher_gate_receipts',
        ));
    }

    private function fastOnly(array $c): Finding
    {
        return (new Finding(
            ruleId: 'SAHER.GATE.FAST_ONLY',
            sourceCode: self::SOURCE,
            category: 'operations',
            title: 'التزامٌ شهادتُه من جولةٍ سريعةٍ لا كاملة',
            severity: 'LOW',
            confidence: 'PROVEN',
            assetKey: $c['sha'],
            assetType: 'commit',
            expected: 'بوّابةٌ كاملةٌ قبل الالتزام',
            actual: '`--fast` تتخطّى الاختبارات والضغط الماليَّ المتوازي',
            impact: '**ولا تُستعمل قبل التزامٍ يمسّ المصادقة أو الوسائط أو '
                . 'الصلاحيات أو المال** — وهو نصُّ القاعدة الأولى.',
            suggestedAction: 'تُعاد البوّابةُ كاملةً إن مسّ الالتزامُ شيئاً من ذلك',
            symbol: $c['author'],
        ))->withEvidence(new Evidence(
            'DB_ROW', 'إيصالٌ سريع', "tree: {$c['tree']}\nis_full: 0", 'saher_gate_receipts',
        ));
    }
}
