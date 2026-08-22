<?php

namespace App\Saher\Findings;

/**
 * SAHER-FOUNDATION-004 — **الدليلُ يُقتطَع لا يُشار إليه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * دليلٌ يقول «انظر `RbacController.php:107`» يصير كذباً بعد أوّل تعديل:
 * السطرُ ١٠٧ صار شيئاً آخر، والقارئُ يرى شيفرةً سليمةً فيُغلق اكتشافاً
 * حيّاً. **فيُحفَظ ما قِيس وقتَ القياس، ويبقى الموضعُ إشارةً لا حجّة.**
 *
 * وهو الدرسُ نفسُه المكتوبُ في `CLAUDE.md` عن صفحة ٤١٩ المخزَّنة: قياسٌ
 * مطبوعٌ في شيءٍ يتغيّر يكذب، وكذبتُه تكبر يوماً كلّ يوم.
 */
final class Evidence
{
    public const KINDS = [
        'ROUTE_MIDDLEWARE',   // ما تحمله الوسائطُ فعلاً على مسار
        'CODE_LINE',          // سطرٌ من ملفّ، منقولٌ بنصّه
        'DB_ROW',             // صفٌّ أو تجميعةٌ من قاعدة
        'QUERY_RESULT',       // ناتجُ استعلامٍ مع نصّه
        'COMMAND_OUTPUT',     // مخرجاتُ أمرٍ شُغّل
        'ABSENCE',            // **ما لم يوجد** — وهو دليلٌ كغيره
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly string $body,
        public readonly ?string $collectedBy = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("نوعُ دليلٍ مجهول: {$kind}");
        }

        if (trim($body) === '') {
            // **ودليلٌ فارغٌ ليس دليلاً.** وهو ما يجعل `PROVEN` تصدق.
            throw new \InvalidArgumentException('دليلٌ بلا متن');
        }
    }

    /**
     * **غيابُ الشيء دليلٌ — ويُقال أنّه غياب.**
     *
     * «لا وسيطةَ صلاحيّةٍ على هذا المسار» حقيقةٌ تُقاس، لكنّها تُقرأ خطأً
     * إن عُرضت كنصٍّ فارغ. فتُوسَم `ABSENCE` ويُكتب ما بُحث عنه وأين.
     */
    public static function absence(string $label, string $searchedFor, ?string $by = null): self
    {
        return new self('ABSENCE', $label, $searchedFor, $by);
    }
}
