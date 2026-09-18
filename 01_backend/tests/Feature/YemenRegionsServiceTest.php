<?php

namespace Tests\Feature;

use App\Services\Geo\YemenRegionsService;
use DomainException;
use Tests\TestCase;

class YemenRegionsServiceTest extends TestCase
{
    private function regions(): YemenRegionsService
    {
        return app(YemenRegionsService::class);
    }

    /** @test */
    public function shabwah_has_its_canonical_districts_from_the_local_dataset(): void
    {
        $rows = $this->regions()->districts('YE-SH');

        $this->assertCount(17, $rows);
        $this->assertTrue(collect($rows)->contains(
            fn (array $row) => $row['id'] === 181 && $row['name_ar'] === 'الروضة'
        ));
    }

    /** @test */
    public function district_selection_is_resolved_without_uzlah_or_village_data(): void
    {
        $selected = $this->regions()->resolveDistrict('YE-SH', 181);

        $this->assertSame('YE-SH', $selected['governorate_code']);
        $this->assertSame(181, $selected['district_id']);
        $this->assertSame('الروضة', $selected['district_name']);
        $this->assertArrayNotHasKey('uzlah_id', $selected);
        $this->assertArrayNotHasKey('village_id', $selected);
    }

    /** @test */
    public function a_district_cannot_be_attached_to_the_wrong_governorate(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('DISTRICT_NOT_IN_GOVERNORATE');

        $this->regions()->resolveDistrict('YE-AD', 181);
    }

    /** @test */
    public function source_attribution_is_kept_with_the_imported_data(): void
    {
        $source = $this->regions()->source();

        $this->assertSame('YemenOpenSource/Yemen-info', $source['name'] ?? null);
        $this->assertSame('MIT', $source['license'] ?? null);
        $this->assertSame(
            'governorates_and_districts_only',
            $source['scope'] ?? null,
        );
    }
}
