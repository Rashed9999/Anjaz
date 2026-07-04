<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

/**
 * AMIAL-PHONE-001 — توحيد صيغة الهاتف: كل الصيغ تؤول لشكل قانوني واحد.
 */
class PhoneTest extends TestCase
{
    /** @test */
    public function all_formats_canonicalize_to_one_form(): void
    {
        $forms = [
            '+967771000001', '00967771000001', '967771000001',
            '771000001', '0771000001', '+967 77-100 0001', '967 771 000 001',
        ];
        foreach ($forms as $f) {
            $this->assertSame('967771000001', Phone::canonical($f), "فشل تطبيع: {$f}");
            $this->assertSame('771000001', Phone::national($f));
        }
    }

    /** @test */
    public function variants_cover_every_stored_format(): void
    {
        $v = Phone::variants('+967771000001');
        foreach (['967771000001', '+967771000001', '00967771000001', '771000001', '0771000001'] as $expected) {
            $this->assertContains($expected, $v, "الصيغة {$expected} مفقودة من variants");
        }
    }

    /** @test */
    public function different_numbers_do_not_collide(): void
    {
        $this->assertNotSame(
            Phone::canonical('771000001'),
            Phone::canonical('771000002')
        );
        // لا تقاطع بين مجموعتي الصيغ لرقمين مختلفين
        $this->assertEmpty(array_intersect(
            Phone::variants('771000001'),
            Phone::variants('771000002')
        ));
    }
}
