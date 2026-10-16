<?php

namespace App\Services\Geo;

use App\Support\YemenGovernorates;
use DomainException;

/**
 * AMIAL-YEMEN-REGIONS-002
 *
 * مصدر محلي ثابت للعناوين اليمنية:
 * محافظة -> مديرية.
 *
 * اسم الحي/المنطقة والعنوان التفصيلي يكتبه العميل نصياً؛ لا نخزن
 * قوائم العزل والقرى لأنها غير مطلوبة في رحلة التسجيل الحالية.
 */
class YemenRegionsService
{
    private static ?array $data = null;

    private function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = resource_path('data/yemen_regions_compact.json');
        if (! is_file($path)) {
            throw new DomainException('YEMEN_REGIONS_DATA_MISSING');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['governorates'])) {
            throw new DomainException('YEMEN_REGIONS_DATA_INVALID');
        }

        return self::$data = $decoded;
    }

    public function source(): array
    {
        return (array) ($this->data()['source'] ?? []);
    }

    public function districts(string $governorateCode): array
    {
        $code = YemenGovernorates::codeFromName($governorateCode);
        if ($code === null) {
            throw new DomainException('GOVERNORATE_INVALID');
        }

        $gov = $this->data()['governorates'][$code] ?? null;
        if (! is_array($gov)) {
            return [];
        }

        return array_map(static fn (array $district): array => [
            'id' => (int) $district['id'],
            'name_ar' => (string) $district['ar'],
            'name_en' => (string) ($district['en'] ?? ''),
        ], $gov['districts'] ?? []);
    }

    /**
     * يتحقق أن المديرية المختارة تتبع فعلاً محافظة السكن.
     */
    public function resolveDistrict(string $governorateCode, int $districtId): array
    {
        $code = YemenGovernorates::codeFromName($governorateCode);
        if ($code === null) {
            throw new DomainException('GOVERNORATE_INVALID');
        }

        $gov = $this->data()['governorates'][$code] ?? null;
        if (! is_array($gov)) {
            throw new DomainException('GOVERNORATE_INVALID');
        }

        foreach ($gov['districts'] ?? [] as $district) {
            if ((int) ($district['id'] ?? 0) === $districtId) {
                return [
                    'governorate_code' => $code,
                    'governorate_name' => YemenGovernorates::name($code),
                    'district_id' => $districtId,
                    'district_name' => (string) $district['ar'],
                ];
            }
        }

        throw new DomainException('DISTRICT_NOT_IN_GOVERNORATE');
    }
}
