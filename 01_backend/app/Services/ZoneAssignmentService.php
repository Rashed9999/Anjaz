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

        $this->logAssignment($user->id, $zone, 'kyc_verification', [
            'declared_city' => $declaredCity,
            'admin_id' => $adminId,
        ]);

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
        $user->zone_code = $zone;
        $user->save();

        $this->logAssignment($user->id, $zone, 'admin_decision', [
            'admin_id' => $adminId,
            'reason' => $reason,
            'old_zone' => $oldZone,
        ]);

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

    private function logAssignment(int $userId, string $zone, string $method, array $signals): void
    {
        try {
            DB::table('zone_assignment_logs')->insert([
                'user_id' => $userId,
                'assigned_zone' => $zone,
                'method' => $method,
                'signals' => json_encode($signals, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Zone assignment log failed', ['err' => $e->getMessage()]);
        }
    }
}
