<?php

namespace App\Support\Fees;

/**
 * AMIAL-FEE-TRUTH-009 — **وصفُ عمليّةٍ مسعَّرةٍ واحدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * صفٌّ في سجلّ العمليّات: ما اسمُها بالعربيّة، ومن تُطبَّق عليه، ومن
 * يتحمّلها، وهل لها حصّةُ وكيلٍ أصلاً، ومن يستهلكها في الشيفرة.
 *
 * **ولمَ صنفٌ لا مصفوفة:** الشاشةُ تقرأ منه (‏قائمةٌ منسدلةٌ بأسماءٍ
 * عربيّةٍ لا `SEND_MONEY` خاماً)، والحارسُ يقرأ منه، والمحرّكُ يتحقّق
 * منه. ومصفوفةٌ بمفاتيحَ نصّيّةٍ تُخطئ في موضعٍ ولا يُقال.
 */
final class FeeOperation
{
    /**
     * @param  string  $code  الرمزُ كما في `fee_schemes.code`
     * @param  string  $labelAr  الاسمُ في كلّ شاشةٍ عربيّة
     * @param  string  $labelEn  الاسمُ في التقارير الإنجليزيّة
     * @param  string  $category  التبويبُ في مركز الرسوم
     * @param  array<int,string>  $actors  قيمُ `applies_to` المشروعة
     * @param  array<int,string>  $bearers  قيمُ `bearer` المشروعة
     * @param  bool  $agentCommission  هل لهذه العمليّة حصّةُ وكيلٍ أصلاً؟
     * @param  bool  $zoneScoped  هل تختلف تسعيرتُها بالمنطقة؟
     * @param  array<int,string>  $consumers  الملفّاتُ التي تحسب بها مالاً
     * @param  string  $owner  الخدمةُ أو المسارُ المسؤول
     * @param  string|null  $notWiredReason  إن كانت غيرَ موصولةٍ بعد — ولماذا
     */
    public function __construct(
        public readonly string $code,
        public readonly string $labelAr,
        public readonly string $labelEn,
        public readonly string $category,
        public readonly array $actors,
        public readonly array $bearers,
        public readonly bool $agentCommission,
        public readonly bool $zoneScoped,
        public readonly array $consumers,
        public readonly string $owner,
        public readonly ?string $notWiredReason = null,
    ) {}

    /** **موصولةٌ بمالٍ حيّ** — أي أنّ تسعيرَها يُخصَم فعلاً. */
    public function isLive(): bool
    {
        return $this->notWiredReason === null;
    }

    /**
     * **الاسمُ الذي يُعرض** — عربيٌّ ومعه الرمزُ لمن يبحث به.
     *
     * فشاشةٌ تعرض `FAMILY_FUND_CONTRIB` خاماً تجعل من يضبط الرسمَ يخمّن
     * أيَّ عمليّةٍ يسعّر، ومن خمّن أخطأ في المال.
     */
    public function display(): string
    {
        return $this->labelAr.' — '.$this->code;
    }

    /** @return array<string,mixed> للردّ JSON وللشاشة. */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label_ar' => $this->labelAr,
            'label_en' => $this->labelEn,
            'category' => $this->category,
            'category_ar' => FeeOperationRegistry::categoryLabel($this->category),
            'actors' => $this->actors,
            'bearers' => $this->bearers,
            'agent_commission' => $this->agentCommission,
            'zone_scoped' => $this->zoneScoped,
            'consumers' => $this->consumers,
            'owner' => $this->owner,
            'live' => $this->isLive(),
            'not_wired_reason' => $this->notWiredReason,
        ];
    }
}
