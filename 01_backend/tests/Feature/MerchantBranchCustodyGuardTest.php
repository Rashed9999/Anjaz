<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Merchant\PosDevice;
use App\Models\PosUser;
use App\Models\User;
use App\Services\BranchResolverService;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AMIAL-BRANCH-CUSTODY-001 — لا تكفي ملكية المنشأة في التعدد الفرعي.
 *
 * هذه الاختبارات تمشي على أسوأ المدخلات: ترويسة فرع آخر، موظف يحاول
 * الانتقال بيده، وجهاز من فرع آخر. كلّها يجب أن تفشل قبل أن تنشأ وردية أو
 * حركة مالية؛ فالواجهة لا تعدو كونها عميل HTTP ويمكن التحايل عليها.
 */
class MerchantBranchCustodyGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        return User::factory()->create(['type' => MERCHANT_TYPE, 'is_active' => 1]);
    }

    private function branch(User $merchant, string $name, bool $default = false): Branch
    {
        return Branch::create([
            'merchant_user_id' => $merchant->id,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 6)),
            'is_active' => true,
            'is_default' => $default,
        ]);
    }

    private function device(User $merchant, Branch $branch): PosDevice
    {
        return PosDevice::create([
            'merchant_user_id' => $merchant->id,
            'branch_id' => $branch->id,
            'device_uuid_hash' => hash('sha256', 'branch-device-'.$branch->id),
            'hash_key_version' => 1,
            'device_hint' => 'branch-device',
            'display_name' => 'صندوق الفرع',
            'registered_at' => now(),
            'is_active' => true,
        ]);
    }

    /** @test */
    public function a_branch_is_captured_on_new_shifts_and_sales_schema(): void
    {
        $this->assertTrue(Schema::hasColumn('cashier_shifts', 'branch_id'));
        $this->assertTrue(Schema::hasColumn('merchant_sales', 'branch_id'));

        $merchant = $this->merchant();
        $branch = $this->branch($merchant, 'المكلا', true);

        $shift = app(CashierShiftService::class)->open($merchant, null, '0', null, $branch->id);

        $this->assertSame($branch->id, (int) $shift->branch_id,
            'الوردية لا تحمل الفرع الذي استلم درج النقد');
    }

    /** @test */
    public function a_pos_employee_cannot_override_its_assigned_branch_from_a_header(): void
    {
        $merchant = $this->merchant();
        $mine = $this->branch($merchant, 'عدن', true);
        $other = $this->branch($merchant, 'تعز');
        $staff = User::factory()->create(['is_active' => 1]);
        $pos = PosUser::create([
            'user_id' => $staff->id,
            'merchant_user_id' => $merchant->id,
            'branch_id' => $mine->id,
            'pos_number' => 'POS-ADEN',
            'is_active' => true,
        ]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_AMIAL_BRANCH_ID' => (string) $other->id,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('لا يستطيع موظف نقطة البيع تغيير فرعه');
        app(BranchResolverService::class)->resolveOperational($request, $merchant, $pos);
    }

    /** @test */
    public function a_till_from_another_branch_cannot_open_a_shift_here(): void
    {
        $merchant = $this->merchant();
        $mine = $this->branch($merchant, 'سيئون', true);
        $other = $this->branch($merchant, 'الشحر');
        $foreignTill = $this->device($merchant, $other);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يتبع الفرع التشغيلي');
        app(CashierShiftService::class)->open(
            $merchant, null, '0', $foreignTill->id, $mine->id,
        );
    }
}
