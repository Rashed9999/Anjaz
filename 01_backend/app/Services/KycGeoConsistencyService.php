<?php

namespace App\Services;

use App\Models\User;
use App\Support\YemenGovernorates;

/**
 * AMIAL-GOVERNORATES-001 — مطابقة جغرافية للمراجعة البشرية.
 *
 * تحاكي ما يفعله موظّف الدعم يدوياً: يقرأ محافظة الأصل من الهوية، ومحافظة
 * السكن من وثيقة العنوان، ويقارنهما بجدول المناطق المتفق عليها، وينظر هل
 * موقع الجهاز يوافقهما — ثم يقرّر.
 *
 * **ما تفعله هذه الخدمة وما لا تفعله:**
 * تُجمّع الإشارات وتصفّها وتُسمّي التعارضات. لا تعتمد ولا ترفض. القرار
 * للمراجع البشري، لأن الحالات الطبيعية كثيرة: نازح، طالب، تاجر يسكن عدن
 * وأصله إب، هوية قديمة صادرة قبل النزوح. حاكمٌ آليّ هنا يرفض عملاء
 * شرعيين بالجملة.
 *
 * لذلك المخرجات «أعلام» (flags) لا «حكم».
 */
class KycGeoConsistencyService
{
    /** لا تعارض يمنع الاعتماد. */
    public const LEVEL_OK = 'ok';
    /** يستحقّ نظرة بشرية قبل الاعتماد. */
    public const LEVEL_REVIEW = 'review';
    /** تعارض جوهري مع نطاق التشغيل. */
    public const LEVEL_BLOCK = 'block';

    /**
     * @param array{latitude?:float|null,longitude?:float|null} $deviceLocation
     * @return array{
     *   level:string, origin:array, residence:array, device:array,
     *   operational:bool, flags:array<int,array{code:string,severity:string,message:string}>,
     *   suggested_zone:string
     * }
     */
    public function evaluate(User $user, array $deviceLocation = []): array
    {
        $originCode = YemenGovernorates::codeFromName($user->origin_governorate)
            ?? YemenGovernorates::codeFromName($user->origin_governorate ?? '');
        $residenceCode = YemenGovernorates::codeFromName($user->residence_governorate ?? '');

        $deviceCode = null;
        $lat = $deviceLocation['latitude'] ?? null;
        $lng = $deviceLocation['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            $deviceCode = YemenGovernorates::codeFromCoordinates((float) $lat, (float) $lng);
        }

        $flags = [];

        // ── وثيقة العنوان هي مرجع المنطقة التشغيلية ─────────────────
        if ($residenceCode === null) {
            $flags[] = $this->flag(
                'residence_missing', self::LEVEL_BLOCK,
                'محافظة السكن غير محدّدة — لا يمكن تحديد المنطقة التشغيلية بلا وثيقة عنوان.'
            );
        } elseif (!YemenGovernorates::isOperational($residenceCode)) {
            $flags[] = $this->flag(
                'residence_outside_operational_area', self::LEVEL_BLOCK,
                'محافظة السكن (' . YemenGovernorates::name($residenceCode) . ') خارج نطاق التشغيل المتفق عليه.'
            );
        }

        // ── محافظة الأصل: إشارة لا شرط ──────────────────────────────
        if ($originCode === null) {
            $flags[] = $this->flag(
                'origin_missing', self::LEVEL_REVIEW,
                'محافظة الأصل غير محدّدة — راجعها في صورة الهوية.'
            );
        } elseif ($residenceCode !== null && $originCode !== $residenceCode) {
            // ليست مخالفة: النزوح والعمل والدراسة تفصل الأصل عن السكن.
            $flags[] = $this->flag(
                'origin_differs_from_residence', self::LEVEL_REVIEW,
                'الأصل (' . YemenGovernorates::name($originCode) . ') يختلف عن السكن ('
                . YemenGovernorates::name($residenceCode) . ') — طبيعي، لكن تحقّق من وثيقة العنوان.'
            );
        }

        if ($originCode !== null && !YemenGovernorates::isOperational($originCode)
            && $residenceCode !== null && YemenGovernorates::isOperational($residenceCode)) {
            $flags[] = $this->flag(
                'origin_outside_residence_inside', self::LEVEL_REVIEW,
                'الأصل خارج نطاق التشغيل والسكن داخله — تأكّد أن وثيقة العنوان حديثة وباسم العميل.'
            );
        }

        // ── موقع الجهاز: أضعف الإشارات، ويُزوَّر ─────────────────────
        if ($deviceCode !== null && $residenceCode !== null && $deviceCode !== $residenceCode) {
            $flags[] = $this->flag(
                'device_differs_from_residence', self::LEVEL_REVIEW,
                'موقع الجهاز وقت التسجيل (' . YemenGovernorates::name($deviceCode)
                . ') يخالف محافظة السكن — قد يكون سفراً، وقد يكون موقعاً وهمياً.'
            );
        }

        if ($deviceCode !== null && !YemenGovernorates::isOperational($deviceCode)) {
            $flags[] = $this->flag(
                'device_outside_operational_area', self::LEVEL_REVIEW,
                'موقع الجهاز وقت التسجيل خارج نطاق التشغيل ('
                . YemenGovernorates::name($deviceCode) . ').'
            );
        }

        return [
            'level' => $this->worstLevel($flags),
            'origin' => $this->describe($originCode),
            'residence' => $this->describe($residenceCode),
            'device' => $this->describe($deviceCode),
            'operational' => $residenceCode !== null && YemenGovernorates::isOperational($residenceCode),
            'flags' => $flags,
            // المنطقة المقترحة تتبع السكن دائماً — لا الأصل ولا الجهاز.
            'suggested_zone' => $residenceCode !== null && YemenGovernorates::isOperational($residenceCode)
                ? ZoneAssignmentService::ZONE_SOUTH
                : ZoneAssignmentService::ZONE_OTHER,
        ];
    }

    private function describe(?string $code): array
    {
        return [
            'code' => $code,
            'name' => $code === null ? null : YemenGovernorates::name($code),
            'operational' => $code !== null && YemenGovernorates::isOperational($code),
        ];
    }

    private function flag(string $code, string $severity, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message];
    }

    private function worstLevel(array $flags): string
    {
        foreach ($flags as $flag) {
            if ($flag['severity'] === self::LEVEL_BLOCK) {
                return self::LEVEL_BLOCK;
            }
        }

        return $flags === [] ? self::LEVEL_OK : self::LEVEL_REVIEW;
    }
}
