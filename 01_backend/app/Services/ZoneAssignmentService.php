<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-ZONE-ASSIGN-001 (v2.0)
 *
 * ZoneAssignmentService — يحدد المنطقة التشغيلية الفعلية للمستخدم.
 *
 * **المشكلة التي يحلها:**
 *   قبل v2.0، كل مستخدم جديد كان يحصل على zone_code = 'SOUTH' افتراضياً،
 *   مما يعطّل سياسة "الجنوب فقط" فعلياً (الجميع يُعامَل كجنوبي).
 *
 * **المنهجية متعددة الإشارات (defense in depth):**
 *   1. المدينة المُصرَّح بها في KYC (الأقوى عند التوثيق)
 *   2. GeoIP من عنوان التسجيل (إشارة)
 *   3. مقدمة رقم الهاتف (إشارة ضعيفة في اليمن)
 *   4. اختيار المستخدم (يحتاج تأكيد لاحق)
 *   5. قرار admin (الأعلى سلطة)
 *
 * **القاعدة الأمنية الحاسمة:**
 *   المستخدم الجديد يبدأ بـ zone = 'UNKNOWN' (وليس SOUTH).
 *   UNKNOWN = لا عمليات مالية (read-only) حتى تُحدَّد المنطقة بثقة.
 *   هذا يعكس الافتراض الخطير القديم: من "الجميع مسموح" إلى "ممنوع حتى يثبت".
 */
class ZoneAssignmentService
{
    public const ZONE_SOUTH = 'SOUTH';
    public const ZONE_NORTH = 'NORTH';
    public const ZONE_MIDDLE = 'MIDDLE';
    public const ZONE_OTHER = 'OTHER';
    public const ZONE_UNKNOWN = 'UNKNOWN';

    /**
     * مدن الجنوب (المنطقة التشغيلية المسموحة).
     * تُستخدم لمطابقة المدينة من KYC أو الاختيار.
     */
    private const SOUTH_CITIES = [
        'عدن', 'aden', 'لحج', 'lahij', 'lahj', 'أبين', 'abyan',
        'الضالع', 'dhale', 'aldhalee', 'شبوة', 'shabwah', 'shabwa',
        'حضرموت', 'hadramout', 'hadhramaut', 'المهرة', 'mahrah', 'almahrah',
        'سقطرى', 'socotra', 'soqotra', 'المكلا', 'mukalla',
    ];

    private const NORTH_CITIES = [
        'صنعاء', 'sanaa', "sana'a", 'sana', 'عمران', 'amran',
        'صعدة', 'saada', 'sadah', 'حجة', 'hajjah', 'المحويت', 'mahwit',
        'ذمار', 'dhamar', 'إب', 'ibb', 'تعز', 'taiz', 'taizz',
        'الحديدة', 'hodeidah', 'hudaydah', 'ريمة', 'raymah',
        'البيضاء', 'bayda', 'albayda', 'مأرب', 'marib', 'الجوف', 'jawf',
    ];

    /**
     * إسناد المنطقة عند التسجيل (إشارات أولية فقط).
     * النتيجة الافتراضية: UNKNOWN (آمن — لا مالية حتى التأكيد).
     */
    public function assignOnRegistration(User $user, ?Request $request = null): string
    {
        $signals = $this->gatherSignals($user, $request);

        // عند التسجيل لا نمنح SOUTH تلقائياً أبداً.
        // نسجّل الإشارات، لكن المنطقة تبقى UNKNOWN حتى التوثيق/التأكيد.
        $zone = self::ZONE_UNKNOWN;

        $user->zone_code = $zone;
        $user->save();

        $this->logAssignment($user->id, $zone, 'registration', $signals);

        return $zone;
    }

    /**
     * تحديد المنطقة من توثيق KYC (المدينة في وثيقة الهوية/إثبات العنوان).
     * هذه الأقوى — تُستدعى عند موافقة admin على التوثيق.
     */
    public function assignFromKyc(User $user, string $declaredCity, ?int $adminId = null): string
    {
        $zone = $this->cityToZone($declaredCity);

        $user->zone_code = $zone;
        $user->save();

        // والنطاقُ ها هنا **مشتقٌّ من الوثيقة نفسِها**، فهو يساويها ولا
        // يخالفها أبداً — ويُمرَّر ليُكتب `kyc_zone` صراحةً لا ليُستنتَج.
        $this->logAssignment($user->id, $zone, 'kyc_verification', [
            'declared_city' => $declaredCity,
            'admin_id' => $adminId,
        ], kycZone: $zone);

        Log::info('Zone assigned from KYC', [
            'user_id' => $user->id, 'zone' => $zone, 'city' => $declaredCity,
        ]);

        return $zone;
    }

    /**
     * قرار admin مباشر (الأعلى سلطة — يتجاوز كل الإشارات).
     */
    public function assignByAdmin(User $user, string $zone, int $adminId, string $reason): string
    {
        if (!in_array($zone, [
            self::ZONE_SOUTH, self::ZONE_NORTH, self::ZONE_MIDDLE,
            self::ZONE_OTHER, self::ZONE_UNKNOWN,
        ], true)) {
            throw new \RuntimeException('منطقة غير صالحة');
        }

        $oldZone = $user->zone_code;

        // **يُقرأ ما تقوله الوثيقةُ قبل الكتابة** — فالكتابةُ تُغيّر
        // `zone_code`، ولا تُغيّر محافظةَ السكن، لكنّ القراءةَ بعدها
        // تختلط على من يقرأ الشيفرة. والترتيبُ ها هنا يقول المقصود.
        $kycZone = $this->zoneFromDocuments($user);

        $user->zone_code = $zone;
        $user->save();

        $this->logAssignment($user->id, $zone, 'admin_decision', [
            'admin_id' => $adminId,
            'reason' => $reason,
            'old_zone' => $oldZone,
            // **ولا تُمحى حقيقةُ الوثيقة** — تُحفظ نصّاً كما قُرئت،
            // فالرمزُ وحدَه لا يقول من أيّ مدينةٍ اشتُقّ.
            'documented_city' => $user->residence_governorate,
        ], kycZone: $kycZone);

        Log::warning('Zone manually assigned by admin', [
            'user_id' => $user->id, 'old' => $oldZone, 'new' => $zone, 'admin' => $adminId,
        ]);

        return $zone;
    }

    /**
     * تحويل اسم مدينة إلى منطقة.
     */
    public function cityToZone(string $city): string
    {
        // ══════════════════════════════════════════════════════════════
        // **ورمزُ ISO يُترجَم قبل أن يُطابَق.**
        //
        // قِيس: `users.residence_governorate` يخزّن **رمزاً** (`YE-AD`) لا
        // اسماً — وهو قرارٌ سليمٌ مكتوبٌ في مسار التسجيل («الرمزُ يُقارَن
        // ويُفهرَس، والنصُّ الحرّ لا يُقارَن بشيء»). **وهذه الدالّةُ كانت
        // تطابق أسماءَ المدن وحدَها**، فكلُّ رمزٍ يمرّ عليها يخرج
        // `UNKNOWN` — أي أنّ التوثيقَ يحسم المنطقةَ إلى «غير معروفة».
        //
        // **وعطلٌ يُنتج القيمةَ التي بُني ليُزيلها لا يُرى في أيّ سجلّ**:
        // السطرُ يُكتب، والقيمةُ تبدو محسومة، والحسابُ يبقى ممنوعاً.
        // ══════════════════════════════════════════════════════════════
        $city = \App\Support\YemenGovernorates::name($city) ?? $city;

        $normalized = $this->normalizeCity($city);

        foreach (self::SOUTH_CITIES as $southCity) {
            if ($this->normalizeCity($southCity) === $normalized
                || str_contains($normalized, $this->normalizeCity($southCity))) {
                return self::ZONE_SOUTH;
            }
        }

        foreach (self::NORTH_CITIES as $northCity) {
            if ($this->normalizeCity($northCity) === $normalized
                || str_contains($normalized, $this->normalizeCity($northCity))) {
                return self::ZONE_NORTH;
            }
        }

        return self::ZONE_UNKNOWN;
    }

    /**
     * AMIAL-GEO-ZONE-001 — مراكز المحافظات اليمنية (خط العرض، خط الطول).
     *
     * لماذا مراكز لا حدود مضلّعة: حدود المحافظات ملفّ ضخم يحتاج مكتبة
     * هندسية، والدقّة الزائدة بلا فائدة هنا — نريد اسم محافظة يطمئن العميل
     * ويعبّئ حقل العنوان، لا فصلاً قضائياً في نزاع حدودي.
     *
     * حضرموت وشبوة شاسعتان فلهما مرساتان لكلٍّ منهما (ساحل وداخل)، وإلا
     * وقعت المكلا على أقرب محافظة أخرى.
     */
    private const GOVERNORATE_ANCHORS = [
        // الجنوب
        ['عدن',      12.7855, 45.0187],
        ['لحج',      13.0567, 44.8819],
        ['أبين',     13.1280, 45.3800],
        ['الضالع',   13.6957, 44.7311],
        ['شبوة',     14.5366, 46.8319],
        ['شبوة',     14.0000, 47.5000],
        ['حضرموت',   14.5424, 49.1242],   // المكلا (الساحل)
        ['حضرموت',   15.9422, 48.7869],   // سيئون (الوادي)
        ['حضرموت',   17.5000, 49.5000],   // الصحراء الشمالية
        ['المهرة',   16.2094, 52.1751],
        ['سقطرى',    12.6519, 54.0219],
        // الشمال
        ['صنعاء',    15.3694, 44.1910],
        ['عمران',    15.6594, 43.9439],
        ['صعدة',     16.9402, 43.7637],
        ['حجة',      15.6943, 43.6017],
        ['المحويت',  15.4700, 43.5450],
        ['ذمار',     14.5426, 44.4018],
        ['إب',       13.9667, 44.1833],
        ['تعز',      13.5795, 44.0209],
        ['الحديدة',  14.7979, 42.9545],
        ['ريمة',     14.6278, 43.4239],
        ['البيضاء',  13.9889, 45.5744],
        ['مأرب',     15.4600, 45.3256],
        ['الجوف',    16.1633, 44.7833],
    ];

    /** الإطار الجغرافي لليمن شاملاً سقطرى — خارجه لا نخمّن محافظة. */
    private const YEMEN_BOUNDS = ['lat' => [11.9, 19.2], 'lng' => [41.6, 54.8]];

    /**
     * اسم المحافظة من الإحداثيات، أو null خارج اليمن.
     *
     * تقريبيّ بأقرب مركز — لا يُعتمد عليه في قرار مالي. الغرض عرض اسم
     * مفهوم للعميل وتعبئة حقل العنوان.
     */
    public function governorateFromCoordinates(float $lat, float $lng): ?string
    {
        [$latMin, $latMax] = self::YEMEN_BOUNDS['lat'];
        [$lngMin, $lngMax] = self::YEMEN_BOUNDS['lng'];

        if ($lat < $latMin || $lat > $latMax || $lng < $lngMin || $lng > $lngMax) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach (self::GOVERNORATE_ANCHORS as [$name, $aLat, $aLng]) {
            $d = $this->haversineKm($lat, $lng, $aLat, $aLng);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $name;
            }
        }

        return $best;
    }

    /**
     * المنطقة التشغيلية من الإحداثيات — إشارة لا قرار.
     *
     * ⚠️ لا تُسند هذه النتيجة إلى user->zone_code مباشرة. إحداثيات الجهاز
     * يمكن تزويرها بتطبيق موقع وهمي في دقائق، فجعلها تمنح SOUTH يحوّل
     * سياسة المناطق كلها إلى إجراء شكلي. السلطة تبقى لتوثيق KYC وقرار
     * الإدارة، تماماً كما هو مصمَّم.
     */
    public function zoneFromCoordinates(float $lat, float $lng): string
    {
        $governorate = $this->governorateFromCoordinates($lat, $lng);

        return $governorate === null ? self::ZONE_OTHER : $this->cityToZone($governorate);
    }

    /** المسافة بالكيلومترات بين نقطتين على سطح الأرض. */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * جمع كل الإشارات المتاحة (للتسجيل والـ audit).
     */
    public function gatherSignals(User $user, ?Request $request): array
    {
        return [
            'ip_address' => $request?->ip(),
            'geo_zone' => $this->detectGeoZone($request),
            'phone_signal' => $this->detectPhoneZone($user->phone ?? ''),
            'app_header_zone' => $request?->header('X-Amial-Zone'),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * كشف المنطقة من GeoIP (إشارة فقط، VPN يتجاوزها).
     * يحتاج خدمة GeoIP حقيقية (MaxMind / ipapi) — هنا hook.
     */
    private function detectGeoZone(?Request $request): ?string
    {
        if (!$request) return null;

        // TODO: تكامل خدمة GeoIP حقيقية في production:
        //   $location = app('geoip')->lookup($request->ip());
        //   if ($location->city) return $this->cityToZone($location->city);

        // header من التطبيق (لو موجود) — إشارة ضعيفة
        $appZone = $request->header('X-Amial-Zone');
        if ($appZone && in_array($appZone, [
            self::ZONE_SOUTH, self::ZONE_NORTH, self::ZONE_MIDDLE, self::ZONE_OTHER,
        ], true)) {
            return $appZone;
        }

        return null;
    }

    /**
     * كشف من مقدمة رقم الهاتف (ضعيف جداً في اليمن — كل الأرقام +967).
     */
    private function detectPhoneZone(string $phone): ?string
    {
        // الأرقام اليمنية لا تفرّق شمال/جنوب بشكل موثوق.
        // الأرقام الأرضية القديمة كانت تفرّق (عدن 02، صنعاء 01) لكن الجوال لا.
        // نتركها كـ null — لا يُعتمد عليها.
        return null;
    }

    private function normalizeCity(string $city): string
    {
        $city = mb_strtolower(trim($city));
        $city = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}]/u', '', $city);
        $replacements = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه'];
        $city = strtr($city, $replacements);
        return preg_replace('/\s+/', '', $city);
    }

    /**
     * **درجةُ الثقة لكلّ مصدر — قاعدةٌ مكتوبةٌ لا رقمٌ مخترَع.**
     *
     * ولا يُكتب «٨٧٪»: رقمٌ مئويٌّ لا حسبةَ خلفه **يُقرأ قياساً وليس
     * قياساً**، ومن يراه يبني عليه قراراً. والتعدادُ يقول ما يعرفه:
     *
     *   · `high`   — وثيقةٌ موثّقةٌ راجعها إنسان.
     *   · `medium` — قرارُ موظّفٍ بسببٍ مكتوب: متعمَّدٌ، وقد يخالف الوثيقة.
     *   · `low`    — إشاراتٌ ظرفيّة (هاتفٌ · عنوانُ شبكة) بلا وثيقة.
     */
    public const CONFIDENCE = [
        'kyc_verification' => 'high',
        'admin_decision' => 'medium',
        'registration' => 'low',
    ];

    /**
     * **ما تقوله الوثيقةُ الآن** — أو `null` إن لا وثيقةَ لها.
     *
     * ولا يُقاس التعارضُ بـ`old_zone`: ذاك ما كان مكتوباً قبل لحظة، وقد
     * يكون `UNKNOWN` من التسجيل أو أثرَ تجاوزٍ سابق. **والوثيقةُ شيءٌ
     * آخر**، ومنها وحدَها يُعرف أنّ الإسنادَ خالفها.
     */
    public function zoneFromDocuments(User $user): ?string
    {
        $city = trim((string) ($user->residence_governorate ?? ''));

        if ($city === '') {
            return null;
        }

        $zone = $this->cityToZone($city);

        // **و`UNKNOWN` ليست رأياً للوثيقة** — هي عجزٌ عن قراءتها.
        // فمخالفةُ «لا أعرف» ليست مخالفة.
        return $zone === self::ZONE_UNKNOWN ? null : $zone;
    }

    private function logAssignment(
        int $userId,
        string $zone,
        string $method,
        array $signals,
        ?string $kycZone = null,
    ): void {
        try {
            // ══════════════════════════════════════════════════════════
            // **التعارضُ يُحسب ويُخزَّن — لا يُترَك ليُشتقّ لاحقاً.**
            //
            // فاشتقاقُه بعد شهرٍ يقرأ محافظةَ سكنٍ **قد تكون تغيّرت هي
            // الأخرى**، فيُخرج جواباً عن لحظةٍ غير اللحظة التي وقع فيها
            // القرار. والسجلُّ يُجيب عن وقتِه لا عن وقتِ قراءته.
            // ══════════════════════════════════════════════════════════
            $isOverride = $kycZone !== null && $kycZone !== $zone;

            DB::table('zone_assignment_logs')->insert([
                'user_id' => $userId,
                'assigned_zone' => $zone,
                'method' => $method,
                'is_override' => $isOverride,
                'overrides_source' => $isOverride ? 'kyc_verification' : null,
                'kyc_zone' => $kycZone,
                'confidence' => self::CONFIDENCE[$method] ?? null,
                'signals' => json_encode($signals, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Zone assignment log failed', ['err' => $e->getMessage()]);
        }
    }
}
