<?php

namespace App\Services\Ocr;

/**
 * AMIAL-KYC-OCR-001 — استخراج حقول الهوية من النصّ الخام.
 *
 * الوثيقة (الفصل ٢٣ — OCR Center) تطلب سبعة حقول: الاسم، الرقم الوطني،
 * تاريخ الميلاد، تاريخ الانتهاء، الجنس، الدولة، نوع الوثيقة.
 *
 * **والمبدأ الحاكم هنا هو الامتناع.** كلّ حقلٍ لا يُستخرَج بيقينٍ معقول
 * يُترك **فارغاً** لا يُملأ بأقرب احتمال.
 *
 * والسبب سلوكيّ لا تقنيّ: من يرى مربّعاً فارغاً يقرأ الصورة ويكتب بنفسه،
 * ومن يرى رقماً مكتوباً يمرّ عليه بنظرةٍ ويضغط «اعتماد». فحقلٌ مملوءٌ خطأً
 * أخطر من حقلٍ فارغ — والفرق كلّه في أنّ الأوّل يسرق انتباه المراجع بدل أن
 * يستدعيه.
 *
 * **ولماذا التاريخان يُعاملان بحذرٍ خاصّ:** التاريخ في الهويّات اليمنية قد
 * يُطبع بصيغٍ عدّة، والخلط بين اليوم والشهر يُنتج تاريخاً **صالحاً** لكنّه
 * خطأ — فلا يكشفه فحصُ صحّة. ولذلك لا يُقبل إلّا ما كان قاطعاً (يومٌ فوق
 * ١٢ يحسم الترتيب) أو موافقاً لصيغةٍ صريحة.
 */
class IdFieldExtractor
{
    /** @return array<string, array{value: mixed, certain: bool, note?: string}> */
    public function extract(string $text): array
    {
        $normalized = $this->normalizeDigits($text);

        return array_filter([
            'national_id' => $this->nationalId($normalized),
            'dates' => $this->dates($normalized),
            'gender' => $this->gender($normalized),
            'country' => $this->country($normalized),
            'full_name' => $this->fullName($text),
        ], static fn ($v) => $v !== null);
    }

    /**
     * الأرقام العربية-الهندية تُحوَّل إلى لاتينية قبل أيّ مطابقة.
     *
     * الهويّات اليمنية تطبع الأرقام بالشكلين، وTesseract يُخرجها كما رآها.
     * وبلا التوحيد يفشل كلّ نمطٍ رقميّ على نصف الوثائق — ويبدو ذلك كأنّ
     * المحرّك ضعيف وهو يقرأ جيّداً.
     */
    private function normalizeDigits(string $s): string
    {
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($persian, $latin, str_replace($arabic, $latin, $s));
    }

    /** @return array{value: string, certain: bool}|null */
    private function nationalId(string $text): ?array
    {
        // مُعنوَنٌ صراحةً: أوثق ما يمكن — الرقم مسبوقٌ بكلمته.
        if (preg_match('/(?:الرقم\s*الوطن[يى]|رقم\s*الهوية|ID\s*(?:No|Number))\D{0,12}(\d{8,14})/u', $text, $m)) {
            return ['value' => $m[1], 'certain' => true];
        }

        // بلا عنوان: يُقبل فقط إن كان في النصّ **رقمٌ طويل واحد**. وتعدّدُها
        // يعني أنّ أحدها رقم هوية والبقية تواريخ أو أرقام مسلسلة — واختيارُ
        // أحدها تخمين.
        preg_match_all('/\b\d{9,14}\b/', $text, $all);
        $unique = array_values(array_unique($all[0] ?? []));

        if (count($unique) === 1) {
            return ['value' => $unique[0], 'certain' => false];
        }

        return null;
    }

    /** @return array{birth: ?array, expiry: ?array}|null */
    private function dates(string $text): ?array
    {
        $found = $this->allDates($text);

        if ($found === []) {
            return null;
        }

        $birth = null;
        $expiry = null;

        // مُعنوَنة: الأوثق.
        if (preg_match('/(?:تاريخ\s*الميلاد|Date\s*of\s*Birth|DOB)\D{0,12}(\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4})/u', $text, $m)) {
            $birth = $this->parseDate($m[1], true);
        }
        if (preg_match('/(?:تاريخ\s*(?:الانتهاء|النفاذ)|Expiry|Valid\s*Until)\D{0,12}(\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4})/u', $text, $m)) {
            $expiry = $this->parseDate($m[1], true);
        }

        // بلا عناوين: يُستدلّ بالزمن لا بالترتيب في النصّ. تاريخُ ميلادٍ في
        // المستقبل مستحيل، وتاريخُ انتهاءٍ قبل عشرين سنة ليس انتهاءً بل
        // ميلاداً. وما لم يحسمه ذلك يُترك.
        if ($birth === null && $expiry === null && count($found) >= 1) {
            $past = array_values(array_filter($found, fn ($d) => $d['ts'] < time()));
            $future = array_values(array_filter($found, fn ($d) => $d['ts'] >= time()));

            if (count($past) === 1) {
                $birth = ['value' => $past[0]['iso'], 'certain' => false];
            }
            if (count($future) === 1) {
                $expiry = ['value' => $future[0]['iso'], 'certain' => false];
            }
        }

        return ($birth || $expiry) ? ['birth' => $birth, 'expiry' => $expiry] : null;
    }

    /** @return list<array{iso: string, ts: int}> */
    private function allDates(string $text): array
    {
        preg_match_all('/\b(\d{1,4})[\/\-.](\d{1,2})[\/\-.](\d{1,4})\b/', $text, $ms, PREG_SET_ORDER);

        $out = [];
        foreach ($ms as $m) {
            $d = $this->parseDate($m[0], false);
            if ($d !== null) {
                $out[] = ['iso' => $d['value'], 'ts' => strtotime($d['value']) ?: 0];
            }
        }

        return $out;
    }

    /** @return array{value: string, certain: bool}|null */
    private function parseDate(string $raw, bool $certain): ?array
    {
        $parts = preg_split('/[\/\-.]/', $raw) ?: [];
        if (count($parts) !== 3) {
            return null;
        }

        [$a, $b, $c] = array_map('intval', $parts);

        // سنةٌ رباعية أوّلاً => Y-M-D. وهي الصيغة الوحيدة التي لا تلتبس.
        if ($a > 31) {
            $y = $a; $mo = $b; $d = $c;
        } elseif ($c > 31) {
            $y = $c;
            // اليوم فوق ١٢ يحسم الترتيب قطعاً. وما دونه ملتبس: 03/04 قد
            // تكون الثالث من أبريل أو الرابع من مارس — ويُنتج الخلطُ تاريخاً
            // **صالحاً** لكنّه خطأ، فلا يكشفه فحص صحّة. فيُترك.
            if ($a > 12) {
                $d = $a; $mo = $b;
            } elseif ($b > 12) {
                $d = $b; $mo = $a;
            } else {
                return null;
            }
        } else {
            return null;
        }

        if ($y < 1900 || $y > 2100 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }

        if (!checkdate($mo, $d, $y)) {
            return null;
        }

        return ['value' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'certain' => $certain];
    }

    /** @return array{value: string, certain: bool}|null */
    private function gender(string $text): ?array
    {
        if (preg_match('/\b(ذكر|Male|MALE)\b/u', $text)) {
            return ['value' => 'male', 'certain' => true];
        }
        if (preg_match('/\b(أنثى|انثى|Female|FEMALE)\b/u', $text)) {
            return ['value' => 'female', 'certain' => true];
        }

        return null;
    }

    /** @return array{value: string, certain: bool}|null */
    private function country(string $text): ?array
    {
        if (preg_match('/(الجمهورية\s*اليمنية|اليمن|Yemen|YEM)/u', $text)) {
            return ['value' => 'YE', 'certain' => true];
        }

        return null;
    }

    /** @return array{value: string, certain: bool}|null */
    private function fullName(string $text): ?array
    {
        // الاسم يُقبل **معنوَناً فقط**.
        //
        // واستخراجُه بلا عنوان — بأخذ أطول سطرٍ عربيّ مثلاً — يلتقط اسم
        // الجهة المُصدِرة أو عنوان البطاقة بثقةٍ عالية. وخطأٌ في الاسم يمرّ
        // أسهل من خطأٍ في رقم: الأرقام تُقارَن، والأسماء تُقرأ بسرعة.
        if (preg_match('/(?:الاسم|الإسم|Name)\s*[:\-]?\s*([\p{Arabic}\s]{4,60}|[A-Za-z\s]{4,60})/u', $text, $m)) {
            $name = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');

            if (mb_strlen($name) >= 4) {
                return ['value' => $name, 'certain' => true];
            }
        }

        return null;
    }
}
