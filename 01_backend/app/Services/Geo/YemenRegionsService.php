<?php

namespace App\Services\Geo;

use App\Support\YemenGovernorates;
use DomainException;

/**
 * AMIAL-YEMEN-REGIONS-001
 *
 * مصدر محلي ثابت للعناوين اليمنية:
 * محافظة -> مديرية -> عزلة/منطقة -> قرية/حي.
 *
 * البيانات مشتقة من YemenOpenSource/Yemen-info (MIT) وتُحفظ داخل المشروع
 * حتى لا يصبح التسجيل معتمداً على API خارجي وقت تشغيل التطبيق.
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
            'uzaal_count' => count($district['uzaal'] ?? []),
        ], $gov['districts'] ?? []);
    }

    public function uzaal(int $districtId): array
    {
        $district = $this->findDistrict($districtId);
        if ($district === null) {
            throw new DomainException('DISTRICT_INVALID');
        }

        return array_map(static fn (array $uzlah): array => [
            'id' => (int) $uzlah['id'],
            'name_ar' => (string) $uzlah['ar'],
            'name_en' => (string) ($uzlah['en'] ?? ''),
            'villages_count' => count($uzlah['villages'] ?? []),
        ], $district['uzaal'] ?? []);
    }

    public function villages(int $uzlahId): array
    {
        $uzlah = $this->findUzlah($uzlahId);
        if ($uzlah === null) {
            throw new DomainException('UZLAH_INVALID');
        }

        return array_map(static fn (array $village): array => [
            'id' => (int) $village['id'],
            'name_ar' => (string) $village['ar'],
            'name_en' => (string) ($village['en'] ?? ''),
        ], $uzlah['villages'] ?? []);
    }

    /**
     * يتحقق من أن القيم المختارة تنتمي لبعضها فعلاً، ثم يعيد الأسماء
     * القانونية التي نخزنها مع المعرّفات. لا نثق باسم مرسل من التطبيق.
     */
    public function resolveSelection(
        string $governorateCode,
        int $districtId,
        ?int $uzlahId = null,
        ?int $villageId = null,
    ): array {
        $code = YemenGovernorates::codeFromName($governorateCode);
        if ($code === null) {
            throw new DomainException('GOVERNORATE_INVALID');
        }

        $gov = $this->data()['governorates'][$code] ?? null;
        if (! is_array($gov)) {
            throw new DomainException('GOVERNORATE_INVALID');
        }

        $district = null;
        foreach ($gov['districts'] ?? [] as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $districtId) {
                $district = $candidate;
                break;
            }
        }
        if ($district === null) {
            throw new DomainException('DISTRICT_NOT_IN_GOVERNORATE');
        }

        $uzlah = null;
        if ($uzlahId !== null) {
            foreach ($district['uzaal'] ?? [] as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === $uzlahId) {
                    $uzlah = $candidate;
                    break;
                }
            }
            if ($uzlah === null) {
                throw new DomainException('UZLAH_NOT_IN_DISTRICT');
            }
        }

        $village = null;
        if ($villageId !== null) {
            if ($uzlah === null) {
                throw new DomainException('VILLAGE_REQUIRES_UZLAH');
            }
            foreach ($uzlah['villages'] ?? [] as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === $villageId) {
                    $village = $candidate;
                    break;
                }
            }
            if ($village === null) {
                throw new DomainException('VILLAGE_NOT_IN_UZLAH');
            }
        }

        return [
            'governorate_code' => $code,
            'governorate_name' => YemenGovernorates::name($code),
            'district_id' => $districtId,
            'district_name' => (string) $district['ar'],
            'uzlah_id' => $uzlahId,
            'uzlah_name' => $uzlah ? (string) $uzlah['ar'] : null,
            'village_id' => $villageId,
            'village_name' => $village ? (string) $village['ar'] : null,
        ];
    }

    private function findDistrict(int $districtId): ?array
    {
        foreach ($this->data()['governorates'] as $gov) {
            foreach ($gov['districts'] ?? [] as $district) {
                if ((int) ($district['id'] ?? 0) === $districtId) {
                    return $district;
                }
            }
        }

        return null;
    }

    private function findUzlah(int $uzlahId): ?array
    {
        foreach ($this->data()['governorates'] as $gov) {
            foreach ($gov['districts'] ?? [] as $district) {
                foreach ($district['uzaal'] ?? [] as $uzlah) {
                    if ((int) ($uzlah['id'] ?? 0) === $uzlahId) {
                        return $uzlah;
                    }
                }
            }
        }

        return null;
    }
}
