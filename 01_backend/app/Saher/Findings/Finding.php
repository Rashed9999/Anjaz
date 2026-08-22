<?php

namespace App\Saher\Findings;

/**
 * SAHER-FOUNDATION-003 — **اكتشافٌ قبل أن يُكتب: قيمةٌ لا صفٌّ في جدول.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ صنفٌ لا مصفوفة:** الاكتشافُ يمرّ بأربع يدين — الجامعُ يصنعه،
 * والمحرّكُ يبصمه، والمخزنُ يكتبه، والشاشةُ تقرؤه. ومصفوفةٌ بمفاتيحَ
 * نصّيّة تُخطئ في واحدةٍ منها بلا صوت: مفتاحٌ يُكتب `severity` هنا
 * و`level` هناك فيقرأ المخزنُ `null` **فيصير كلُّ اكتشافٍ `INFO`**.
 *
 * والبنّاءُ يرفض ما لا يعرفه، فالخطأُ يقع عند الكتابة لا عند العرض.
 */
final class Finding
{
    public const SEVERITIES = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO'];

    /**
     * **درجاتُ الثقة أربعٌ ولكلٍّ معنىً تشغيليّ:**
     *
     *   PROVEN            — قِيس مباشرةً من المصدر، ولا يحتمل تأويلاً
     *   HIGH_CONFIDENCE   — استُنتج من قياسٍ سليمٍ ويحتمل استثناءً نادراً
     *   SUSPECTED         — مؤشِّرٌ يستحقّ النظر، **ولا يُبنى عليه حذف**
     *   INFORMATIONAL     — يُقال ولا يُطالَب بفعل
     *
     * **والفرقُ بين الثانية والثالثة هو ما يمنع حذفَ شيفرةٍ حيّة.** «صنفٌ
     * بلا مرجع» يبدو مثبَتاً وهو ليس كذلك: قد يُنادى بانعكاسٍ أو باسمٍ
     * مبنيٍّ في وقت التشغيل. فهو `SUSPECTED` أبداً.
     */
    public const CONFIDENCES = ['PROVEN', 'HIGH_CONFIDENCE', 'SUSPECTED', 'INFORMATIONAL'];

    /** @var list<Evidence> */
    public array $evidence = [];

    public function __construct(
        public readonly string $ruleId,
        public readonly string $sourceCode,
        public readonly string $category,
        public readonly string $title,
        public readonly string $severity,
        public readonly string $confidence,
        /** ما يميّز هذا الاكتشافَ عن أخيه — يدخل في البصمة. */
        public readonly string $assetKey,
        public readonly ?string $assetType = null,
        public readonly ?string $expected = null,
        public readonly ?string $actual = null,
        public readonly ?string $impact = null,
        public readonly ?string $suggestedAction = null,
        public readonly ?string $filePath = null,
        public readonly ?int $lineStart = null,
        public readonly ?int $lineEnd = null,
        public readonly ?string $symbol = null,
    ) {
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException("درجةُ خطرٍ مجهولة: {$severity}");
        }

        if (! in_array($confidence, self::CONFIDENCES, true)) {
            throw new \InvalidArgumentException("درجةُ ثقةٍ مجهولة: {$confidence}");
        }

        if (trim($assetKey) === '') {
            // **بلا أصلٍ لا بصمة**، وبلا بصمةٍ يتكرّر العطلُ الواحدُ ألفاً.
            throw new \InvalidArgumentException('اكتشافٌ بلا أصلٍ — لا تُبنى له بصمة');
        }
    }

    public function withEvidence(Evidence ...$items): self
    {
        foreach ($items as $item) {
            $this->evidence[] = $item;
        }

        return $this;
    }

    /**
     * **البصمةُ تُشتقّ ولا تُكتب.**
     *
     * (القاعدة + الأصل) — **ولا يدخل فيها العنوانُ ولا نصُّ الدليل**:
     * تحسينُ صياغةٍ في رسالةِ قاعدةٍ لا يجوز أن يُنتج اكتشافاً «جديداً»
     * ويُغلق القديمَ، فيضيع «منذ متى» وهو أثمنُ ما في السجلّ.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->ruleId . '|' . $this->assetKey);
    }

    public function hasProof(): bool
    {
        return $this->evidence !== [];
    }
}
