<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PharmacySale;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierService;
use App\Services\PharmacyService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-POS-DAILY-REPORT-001
 *
 * تقرير موظف نقطة البيع ليس تقرير المنشأة كلها. هذا الحارس يمنع رجوع
 * تقرير الصيدلية إلى merchant_sales أو جمع مبيعات موظف آخر في الرقم نفسه.
 */
class PosDailyVerticalReportGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function pharmacy_pos_report_reads_pharmacy_sales_and_only_the_current_employee(): void
    {
        $merchant = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_PHARMACY,
            'business_name' => 'صيدلية تقرير POS',
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        $mine = User::factory()->create(['role' => 'pos', 'zone_code' => 'SOUTH']);
        $other = User::factory()->create(['role' => 'pos', 'zone_code' => 'SOUTH']);

        $myPos = PosUser::create([
            'user_id' => $mine->id,
            'merchant_user_id' => $merchant->id,
            'pos_number' => 'PH-RPT-001',
            'display_name' => 'صيدلي 1',
            'is_active' => true,
        ]);
        $otherPos = PosUser::create([
            'user_id' => $other->id,
            'merchant_user_id' => $merchant->id,
            'pos_number' => 'PH-RPT-002',
            'display_name' => 'صيدلي 2',
            'is_active' => true,
        ]);

        $pharmacy = app(PharmacyService::class)->getOrCreatePharmacy($merchant, [
            'name' => 'صيدلية تقرير POS',
        ]);

        PharmacySale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'pos_user_id' => $myPos->id,
            'created_by_user_id' => $mine->id,
            'pharmacy_id' => $pharmacy->id,
            'subtotal' => '700.0000',
            'discount_amount' => '0.0000',
            'total_amount' => '700.0000',
            'payment_method' => 'cash',
            'status' => 'completed',
            'zone_code' => 'SOUTH',
        ]);

        PharmacySale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'pos_user_id' => $otherPos->id,
            'created_by_user_id' => $other->id,
            'pharmacy_id' => $pharmacy->id,
            'subtotal' => '900.0000',
            'discount_amount' => '0.0000',
            'total_amount' => '900.0000',
            'payment_method' => 'amial_pay',
            'status' => 'completed',
            'zone_code' => 'SOUTH',
        ]);

        $report = app(CashierService::class)->dailyReportForPos(
            $merchant,
            $myPos->id,
            $mine->id,
        );

        $this->assertSame('pharmacy_sales', $report['source']);
        $this->assertSame('pos_user', $report['report_scope']);
        $this->assertSame(1, $report['sales_count']);
        $this->assertSame('700.0000', $report['total_all']);
        $this->assertSame('700.0000', $report['by_method']['cash']);
        $this->assertSame('0.0000', $report['by_method']['amial_pay']);
        $this->assertNull($report['outstanding_credit_total']);
    }
}
