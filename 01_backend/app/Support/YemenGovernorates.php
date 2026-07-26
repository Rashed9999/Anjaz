<?php

namespace App\Support;

/**
 * AMIAL-GOVERNORATES-001 — جدول المحافظات اليمنية الكامل.
 *
 * مصدر واحد للحقيقة يحلّ محلّ قوائم المدن المبعثرة. المحافظات الـ22
 * (21 محافظة + أمانة العاصمة، وسقطرى محافظة مستقلّة منذ 2013) برموز
 * ISO 3166-2:YE.
 *
 * **تمييز مقصود:** هذا الجدول جغرافيا لا سياسة. أيّ المحافظات تعمل فيها
 * أميال باي مسألة تشغيلية منفصلة تُضبط في config('amial.operational_governorates')
 * — لأن خريطة السيطرة تتغيّر، ولا يجوز أن يتطلّب تغيُّرها تعديل شيفرة.
 *
 * الأسماء البديلة (aliases) لمطابقة ما يكتبه المستخدم أو ما يظهر في
 * الهوية: «صنعا»، «الحديده»، «Aden»، «Taizz»…
 */
final class YemenGovernorates
{
    /**
     * [رمز ISO => [الاسم العربي، الإنجليزي، المرساة lat/lng، أسماء بديلة]]
     *
     * المرساة = مركز تقريبي للمحافظة، تُستعمل في مطابقة الإحداثيات.
     */
    private const TABLE = [
        'YE-AB' => ['أبين',           'Abyan',           13.1280, 45.3800, ['ابين', 'زنجبار', 'zinjibar']],
        'YE-AD' => ['عدن',            'Aden',            12.7855, 45.0187, ['عدن', 'كريتر', 'crater', 'المعلا', 'خورمكسر']],
        'YE-AM' => ['عمران',          'Amran',           15.6594, 43.9439, ['عمران']],
        'YE-BA' => ['البيضاء',        'Al Bayda',        13.9889, 45.5744, ['البيضا', 'bayda', 'albayda']],
        'YE-DA' => ['الضالع',         "Ad Dali'",        13.6957, 44.7311, ['الضالع', 'ضالع', 'dhale', 'aldhalee']],
        'YE-DH' => ['ذمار',           'Dhamar',          14.5426, 44.4018, ['ذمار']],
        'YE-HD' => ['حضرموت',         'Hadhramaut',      15.9422, 48.7869, ['حضرموت', 'المكلا', 'mukalla', 'سيئون', 'seiyun', 'hadramout', 'hadhramawt']],
        'YE-HJ' => ['حجة',            'Hajjah',          15.6943, 43.6017, ['حجه', 'hajja']],
        'YE-HU' => ['الحديدة',        'Al Hudaydah',     14.7979, 42.9545, ['الحديده', 'hodeidah', 'hudaydah']],
        'YE-IB' => ['إب',             'Ibb',             13.9667, 44.1833, ['اب', 'ibb']],
        'YE-JA' => ['الجوف',          'Al Jawf',         16.1633, 44.7833, ['الجوف', 'jawf', 'الحزم']],
        'YE-LA' => ['لحج',            'Lahij',           13.0567, 44.8819, ['لحج', 'الحوطة', 'lahj']],
        'YE-MA' => ['مأرب',           "Ma'rib",          15.4600, 45.3256, ['مارب', 'marib']],
        'YE-MR' => ['المهرة',         'Al Mahrah',       16.2094, 52.1751, ['المهره', 'الغيضة', 'ghaydah', 'mahrah']],
        'YE-MW' => ['المحويت',        'Al Mahwit',       15.4700, 43.5450, ['المحويت', 'mahwit']],
        'YE-RA' => ['ريمة',           'Raymah',          14.6278, 43.4239, ['ريمه', 'raymah']],
        'YE-SA' => ['أمانة العاصمة',  'Amanat Al Asimah',15.3547, 44.2066, ['امانه العاصمه', 'العاصمة', 'مدينة صنعاء', 'sanaa city']],
        'YE-SD' => ['صعدة',           "Sa'dah",          16.9402, 43.7637, ['صعده', 'saada', 'sadah']],
        'YE-SH' => ['شبوة',           'Shabwah',         14.5366, 46.8319, ['شبوه', 'عتق', 'ataq', 'shabwa']],
        'YE-SN' => ['صنعاء',          "Sana'a",          15.3694, 44.1910, ['صنعا', 'sanaa', "sana'a", 'sana']],
        'YE-SU' => ['سقطرى',          'Socotra',         12.6519, 54.0219, ['سقطرة', 'حديبو', 'hadibu', 'socotra', 'soqotra']],
        'YE-TA' => ['تعز',            'Taiz',            13.5795, 44.0209, ['تعز', 'taiz', 'taizz']],
    ];

    /**
     * مرساة إضافية للمحافظات الشاسعة — بدونها تقع المكلا (ساحل حضرموت)
     * على أقرب محافظة أخرى بدل حضرموت.
     */
    private const EXTRA_ANCHORS = [
        ['YE-HD', 14.5424, 49.1242],   // المكلا
        ['YE-HD', 17.5000, 49.5000],   // شمال حضرموت الصحراوي
        ['YE-SH', 14.0000, 47.5000],   // جنوب شبوة
        ['YE-MR', 17.5000, 51.5000],   // شمال المهرة
    ];

    /** الإطار الجغرافي لليمن شاملاً سقطرى. */
    public const BOUNDS = ['lat' => [11.9, 19.2], 'lng' => [41.6, 54.8]];

    /** كل المحافظات جاهزة للعرض في قائمة منسدلة. */
    public static function all(): array
    {
        $out = [];
        foreach (self::TABLE as $code => [$ar, $en, , , ]) {
            $out[] = ['code' => $code, 'name' => $ar, 'name_en' => $en];
        }

        // ترتيب عربي مستقرّ — القائمة تُعرض للمستخدم لا للآلة.
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** الرموز الصالحة — للتحقّق من المدخلات. */
    public static function codes(): array
    {
        return array_keys(self::TABLE);
    }

    public static function isValidCode(?string $code): bool
    {
        return $code !== null && isset(self::TABLE[$code]);
    }

    /** الاسم العربي من الرمز. */
    public static function name(?string $code): ?string
    {
        return self::TABLE[$code][0] ?? null;
    }

    /**
     * الرمز من نصّ حرّ: رمز، أو اسم عربي/إنجليزي، أو اسم بديل، أو مدينة.
     * يُستعمل لمطابقة ما كتبه المستخدم وما يقرؤه الموظّف من الهوية.
     */
    public static function codeFromName(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        if (isset(self::TABLE[$input])) {
            return $input;
        }

        $needle = self::normalize($input);

        foreach (self::TABLE as $code => [$ar, $en, , , $aliases]) {
            $candidates = array_merge([$ar, $en], $aliases);
            foreach ($candidates as $candidate) {
                if (self::normalize($candidate) === $needle) {
                    return $code;
                }
            }
        }

        // مطابقة جزئية أخيراً: «مديرية صيرة - عدن» يجب أن تجد عدن.
        foreach (self::TABLE as $code => [$ar, $en, , , $aliases]) {
            foreach (array_merge([$ar, $en], $aliases) as $candidate) {
                $c = self::normalize($candidate);
                if (mb_strlen($c) >= 3 && str_contains($needle, $c)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /** أقرب محافظة للإحداثيات، أو null خارج اليمن. */
    public static function codeFromCoordinates(float $lat, float $lng): ?string
    {
        [$latMin, $latMax] = self::BOUNDS['lat'];
        [$lngMin, $lngMax] = self::BOUNDS['lng'];

        if ($lat < $latMin || $lat > $latMax || $lng < $lngMin || $lng > $lngMax) {
            return null;
        }

        $anchors = [];
        foreach (self::TABLE as $code => [, , $aLat, $aLng, ]) {
            $anchors[] = [$code, $aLat, $aLng];
        }
        foreach (self::EXTRA_ANCHORS as $extra) {
            $anchors[] = $extra;
        }

        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($anchors as [$code, $aLat, $aLng]) {
            $d = self::haversineKm($lat, $lng, $aLat, $aLng);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $code;
            }
        }

        return $best;
    }

    /**
     * هل المحافظة ضمن نطاق التشغيل المتفق عليه؟
     *
     * القائمة في config('amial.operational_governorates') لا هنا: خريطة
     * السيطرة تتغيّر، وتغييرها يجب أن يكون قرار إعداد لا إصدار برمجي.
     */
    public static function isOperational(?string $code): bool
    {
        if (!self::isValidCode($code)) {
            return false;
        }

        return in_array($code, (array) config('amial.operational_governorates', []), true);
    }

    /** توحيد النصّ العربي: تشكيل، همزات، تاء مربوطة، مسافات. */
    private static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}]/u', '', $t);
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $t = str_replace(['-', '_', "'", '`'], '', $t);

        return preg_replace('/\s+/', '', $t);
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
