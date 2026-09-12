<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants as A;
use App\Support\Access\Capability;
use Tests\TestCase;

/**
 * لا يوسّع استدعاءٌ ثانٍ لنطاق القدرة ما قيّده الاستدعاء الأول.
 * هذا يمنع «businessTypes([retail]) ثم GOODS» من إظهار مركز التجزئة
 * في الصيدلية أو محطة الوقود بسبب ترتيب سطور السجل فقط.
 */
class CapabilityScopeRefinementTest extends TestCase
{
    /** @test */
    public function a_second_scope_declaration_can_only_narrow_the_first_one(): void
    {
        $capability = Capability::make('scope-test')
            ->businessTypes([A::BIZ_RETAIL])
            ->businessTypes([A::BIZ_RETAIL, A::BIZ_WHOLESALE, A::BIZ_PHARMACY]);

        $this->assertTrue($capability->appliesTo(A::BIZ_RETAIL));
        $this->assertFalse($capability->appliesTo(A::BIZ_WHOLESALE));
        $this->assertFalse($capability->appliesTo(A::BIZ_PHARMACY));
    }
}
