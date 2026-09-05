<?php

namespace App\Domain\Plans;

use App\Support\Access\AccessConstants as A;

/**
 * AMIAL-PLAN-OOP-001 — **المربّع الثاني: الباقة تبيع سقفاً، لا تبيع نشاطاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع، مقيساً:** «الباقة» متفرّقةٌ في **١٤٢ موضعاً** في
 * الخادم. فالحدُّ يُقرأ من `PLAN_LIMITS`، والسعرُ من `PLAN_PRICES_SAR`،
 * والاسمُ من `PLAN_LABELS`، والترتيبُ من `ALL_PLANS` — أربعةُ مصادرَ
 * لشيءٍ واحد.
 *
 * **وقد افترقت فعلاً:** «كم منتجاً» كان جواباً في موضعين، فسقط ثلاثةُ
 * حرّاسٍ حين غيّر صاحبُ المشروع التسعير — لا على عطل، بل على **قرارٍ
 * سليم**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والباقةُ لا تعرف نوعَ النشاط إطلاقاً.**
 *
 * هي تبيع **سقفاً**: كم منتجاً، كم موظّفاً، كم فرعاً، كم جهازاً، كم
 * عمليّةً في الشهر، وكم يوماً يُحفظ الأرشيف. **ولا تبيع صيدليّةً.**
 *
 * وخلطُ الاثنين هو ما جعل قطاعاً يُسأل عن سعرٍ وباقةً تُسأل عن ميزةِ
 * صيدليّة — فيفترق الجوابان.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقيمُ تُقرأ من `AccessConstants` ولا تُنسخ.** هذا المربّعُ **وجهٌ
 * كائنيٌّ لمصدرٍ واحدٍ قائم**، لا مصدرٌ ثانٍ ينافسه. ونسختان لحقيقةٍ
 * واحدةٍ تفترقان أوّلَ ما يتغيّر أحدُهما — وقد افترقتا في هذا المشروع
 * أربعَ مرّاتٍ مقيسة.
 */
final class MerchantPlan
{
    private function __construct(
        public readonly string $code,
    ) {
    }

    /**
     * @throws \InvalidArgumentException على رمزٍ ليس في الفهرس المُباع
     */
    public static function of(string $code): self
    {
        // **والاسمُ المهجورُ يُطبَّع لا يُرفَض** — صفٌّ قديمٌ في القاعدة
        // قد يحمل `starter` أو `merchant_pro`، وصاحبُه لا ذنبَ له.
        $canonical = A::canonicalPlan($code);

        if (! in_array($canonical, A::ALL_PLANS, true)) {
            throw new \InvalidArgumentException("باقةٌ ليست في الفهرس: {$code}");
        }

        return new self($canonical);
    }

    /** كلُّ الباقات المُباعة، بترتيبها. @return array<int,self> */
    public static function all(): array
    {
        return array_map(static fn (string $c) => new self($c), A::ALL_PLANS);
    }

    // ══════════════════════════════════════════════════════════════════
    //  الاسمُ والسعر
    // ══════════════════════════════════════════════════════════════════

    public function nameAr(): string
    {
        return A::PLAN_LABELS[$this->code] ?? $this->code;
    }

    public function monthlyPrice(): int
    {
        return (int) (A::PLAN_PRICES_SAR[$this->code] ?? 0);
    }

    public function yearlyPrice(): int
    {
        return (int) (A::PLAN_PRICES_SAR_ANNUAL[$this->code] ?? 0);
    }

    /**
     * **والعملةُ تُقال ولا تُحوَّل.**
     *
     * الأسعارُ بالريال السعوديّ كما يقول اسمُ الثابت، وكلُّ رصيدٍ في
     * المنتج بالريال اليمنيّ. والتحويلُ الصامتُ يحتاج سعرَ صرفٍ ومصدرَه
     * وطابعَه الزمنيّ — وثلاثتُها غيرُ موجودة، فيستبدل كذبةً بأطولَ منها
     * عمراً. (وقد قرأ قارئٌ «٣٥ ر.ي» فظنّ الباقةَ بثمن كوبِ شاي.)
     */
    public function currency(): string
    {
        return A::PLAN_PRICE_CURRENCY;
    }

    public function isFree(): bool
    {
        return $this->code === A::PLAN_FREE;
    }

    // ══════════════════════════════════════════════════════════════════
    //  السقوف — وهي كلُّ ما تبيعه الباقة
    // ══════════════════════════════════════════════════════════════════

    /** @return array<string,int> */
    public function limits(): array
    {
        return A::PLAN_LIMITS[$this->code] ?? A::PLAN_LIMITS[A::PLAN_FREE];
    }

    public function maxProducts(): int   { return $this->limit('products'); }
    public function maxEmployees(): int  { return $this->limit('employees'); }
    public function maxBranches(): int   { return $this->limit('branches'); }
    public function maxPosDevices(): int { return $this->limit('pos_devices'); }
    public function monthlyOperations(): int { return $this->limit('monthly_operations'); }
    public function archiveDays(): int   { return $this->limit('archive_days'); }

    private function limit(string $key): int
    {
        return (int) ($this->limits()[$key] ?? 0);
    }

    // ══════════════════════════════════════════════════════════════════
    //  قراءةُ السقف — وثلاثُ حالاتٍ لا اثنتان
    // ══════════════════════════════════════════════════════════════════

    /**
     * **`-1` بلا حدّ · `0` ممنوع · وما فوقهما سقفٌ منتهٍ.**
     *
     * وخلطُ `0` بـ`-1` عطلٌ ماليّ: الأوّلُ يمنع من الصفّ الأوّل، والثاني
     * يفتح بلا نهاية. ومن قرأهما سواءً باع بلا حدٍّ أو منع بلا سبب.
     */
    public function isUnlimited(string $key): bool
    {
        return $this->limit($key) === -1;
    }

    public function isForbidden(string $key): bool
    {
        return $this->limit($key) === 0;
    }

    /** أيَسَعُ السقفُ عدداً بعينه؟ */
    public function allows(string $key, int $count): bool
    {
        $max = $this->limit($key);

        return $max === -1 || $count <= $max;
    }

    // ══════════════════════════════════════════════════════════════════
    //  الترتيب — يُقرأ من الفهرس لا يُكتب سُلَّماً
    // ══════════════════════════════════════════════════════════════════

    /**
     * أتبلغ هذه الباقةُ باقةً مطلوبة؟
     *
     * **والترتيبُ من `ALL_PLANS`** — فباقةٌ تُضاف غداً تأخذ موضعَها بلا
     * تعديلٍ هنا. وسُلَّمٌ مكتوبٌ بيدٍ يشيخ مع أوّل قرارِ تسعير، وقد شاخ
     * ثلاثَ مرّاتٍ في هذا المشروع.
     */
    public function reaches(string $required): bool
    {
        $order = A::ALL_PLANS;

        $mine = array_search($this->code, $order, true);
        $need = array_search(A::canonicalPlan($required), $order, true);

        return $mine !== false && $need !== false && $mine >= $need;
    }

    /** الباقةُ التي تليها — أو `null` إن كانت الأعلى. */
    public function next(): ?self
    {
        $order = A::ALL_PLANS;
        $i = array_search($this->code, $order, true);

        return ($i === false || $i + 1 >= count($order))
            ? null
            : new self($order[$i + 1]);
    }
}
