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
    public function district_uzlah_and_village_form_one_verified_chain(): void
    {
        $uzaal = $this->regions()->uzaal(181);
        $this->assertTrue(collect($uzaal)->contains(
            fn (array $row) => $row['id'] === 1508 && $row['name_ar'] === 'الروضه'
        ));

        $villages = $this->regions()->villages(1508);
        $this->assertTrue(collect($villages)->contains(
            fn (array $row) => $row['id'] === 19271 && $row['name_ar'] === 'الحوطه'
        ));

        $selected = $this->regions()->resolveSelection('YE-SH', 181, 1508, 19271);

        $this->assertSame('الروضة', $selected['district_name']);
        $this->assertSame('الروضه', $selected['uzlah_name']);
        $this->assertSame('الحوطه', $selected['village_name']);
    }

    /** @test */
    public function a_district_cannot_be_attached_to_the_wrong_governorate(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('DISTRICT_NOT_IN_GOVERNORATE');

        $this->regions()->resolveSelection('YE-AD', 181, 1508, 19271);
    }

    /** @test */
    public function source_attribution_is_kept_with_the_imported_data(): void
    {
        $source = $this->regions()->source();

        $this->assertSame('YemenOpenSource/Yemen-info', $source['name'] ?? null);
        $this->assertSame('MIT', $source['license'] ?? null);
    }
}
