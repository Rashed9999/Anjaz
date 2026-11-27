<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FamilyFund;
use App\Models\FamilyFundTransaction;
use App\Models\User;
use App\Services\FamilyFundService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * اختبارات انحدار الصندوق العائلي:
 * - منع الصرف المزدوج عند تكرار الاعتماد (Replay / Concurrency)
 * - حفظ حالة الرفض عند نقص الرصيد وقت الاعتماد دون تراجع صامت (Partial Failure)
 * - تجميد وفك تجميد الصندوق ومنع الحركات أثناء التجميد
 * - صلاحيات لوحة الإدارة ومطابقة الرصيد بعد الصرف المعتمد
 */
class FamilyFundRegressionTest extends TestCase
{
    use RefreshDatabase;

    private FamilyFundService $service;
    private User $owner;
    private User $adminMember;
    private User $beneficiary;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial.operational_governorates' => ['YE-AD']]);
        Queue::fake();

        $this->service = app(FamilyFundService::class);

        $this->owner = $this->tierTwoCustomer(['phone' => '+967701000001']);
        EMoney::create(['user_id' => $this->owner->id, 'current_balance' => '2000.0000']);

        $this->adminMember = $this->tierTwoCustomer(['phone' => '+967701000002']);
        EMoney::create(['user_id' => $this->adminMember->id, 'current_balance' => '500.0000']);

        $this->beneficiary = $this->tierTwoCustomer(['phone' => '+967701000003']);
        EMoney::create(['user_id' => $this->beneficiary->id, 'current_balance' => '100.0000']);
    }

    private function createFundedPool(string $initialAmount = '500.0000', bool $requireApproval = true): FamilyFund
    {
        $fund = $this->service->create(
            owner: $this->owner,
            name: 'صندوق الأسرة',
            description: 'اختبار انحدار',
            requireOwnerApproval: $requireApproval,
        );

        $m1 = $this->service->inviteMember($fund, $this->owner, $this->adminMember->phone, 'admin');
        $this->service->acceptInvitation($m1, $this->adminMember);

        $m2 = $this->service->inviteMember($fund, $this->owner, $this->beneficiary->phone, 'member');
        $this->service->acceptInvitation($m2, $this->beneficiary);

        $this->service->contribute($fund->fresh(), $this->owner, $initialAmount, 'إيداع أولي');

        return $fund->fresh();
    }

    private function platformAdmin(string $role = PlatformRoleService::ADMIN): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'admin',
            'is_active' => 1,
        ]);
        app(PlatformRoleService::class)->assign($u, $role);

        return $u->refresh();
    }

    /** @test */
    public function replaying_disbursement_approval_never_double_spends(): void
    {
        $fund = $this->createFundedPool('500.0000', true);

        $proposal = $this->service->proposeDisbursement(
            fund: $fund,
            proposer: $this->adminMember,
            beneficiary: $this->beneficiary,
            amount: '200.0000',
            note: 'مصاريف علاج',
        );
        $this->assertSame('pending_approval', $proposal->status);

        // الاعتماد الأول ينجح ويحرّك المال مرة واحدة فقط
        $approved = $this->service->approveDisbursement($proposal, $this->owner);
        $this->assertTrue($approved);
        $this->assertSame('completed', $proposal->fresh()->status);

        // إعادة طلب الاعتماد (محاكاة Replay / Race) يجب أن تُرفض دون خصم أو إيداع إضافي
        $replayed = $this->service->approveDisbursement($proposal->fresh(), $this->owner);
        $this->assertFalse($replayed, 'إعادة اعتماد الصرف المعتمد مسبقاً يجب أن تُرفض');

        // التحقق من ثبات الأرصدة: الصندوق = 300، المستفيد = 300 (100 + 200 مرة واحدة فقط)
        $this->assertSame('300.0000', (string) $fund->fresh()->balance);
        $this->assertSame(
            '300.0000',
            (string) EMoney::where('user_id', $this->beneficiary->id)->value('current_balance')
        );
    }

    /** @test */
    public function insufficient_balance_at_approval_persists_rejected_status_without_rollback(): void
    {
        $fund = $this->createFundedPool('300.0000', true);

        // مقترحان معلّقان بقيمة 200 لكل منهما (المجموع 400 > رصيد 300)
        $first = $this->service->proposeDisbursement($fund, $this->adminMember, $this->beneficiary, '200.0000', 'طلب أول');
        $second = $this->service->proposeDisbursement($fund->fresh(), $this->adminMember, $this->beneficiary, '200.0000', 'طلب ثانٍ');

        // اعتماد الأول ينجح ويترك 100 فقط في الصندوق
        $this->service->approveDisbursement($first, $this->owner);
        $this->assertSame('100.0000', (string) $fund->fresh()->balance);

        // اعتماد الثاني يجب أن يفشل لنقص الرصيد، مع حفظ حالته rejected فعلياً (لا يتراجع إلى pending_approval)
        $thrown = false;
        try {
            $this->service->approveDisbursement($second, $this->owner);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('insufficient', strtolower($e->getMessage()));
        }

        $this->assertTrue($thrown);
        $this->assertSame('rejected', $second->fresh()->status, 'يجب حفظ حالة الرفض خارج معاملة التراجع');
        $this->assertSame('100.0000', (string) $fund->fresh()->balance);
        $this->assertSame(
            '300.0000',
            (string) EMoney::where('user_id', $this->beneficiary->id)->value('current_balance')
        );
    }

    /** @test */
    public function freezing_a_fund_blocks_all_financial_and_membership_mutations_until_unfrozen(): void
    {
        $fund = $this->createFundedPool('400.0000', true);
        $pendingTx = $this->service->proposeDisbursement($fund, $this->adminMember, $this->beneficiary, '100.0000', 'طلب قبل التجميد');
        $admin = $this->platformAdmin();

        // تجميد عبر مسار لوحة الإدارة
        $this->actingAs($admin, 'user')
            ->post(route('admin.amial.surface.funds.status', $fund->id), [
                'action' => 'freeze',
                'reason' => 'مراجعة امتثال مؤقتة لحركات الصندوق',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fund->refresh();
        $this->assertSame('frozen', $fund->status);

        // تكرار التجميد على صندوق مجمّد يُرفض دون إفساد الحالة
        $this->actingAs($admin, 'user')
            ->post(route('admin.amial.surface.funds.status', $fund->id), [
                'action' => 'freeze',
                'reason' => 'محاولة تجميد مكررة',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame('frozen', $fund->fresh()->status);

        // المساهمة، اقتراح الصرف، واعتماد الصرف المعلّق كلها محجوبة أثناء التجميد
        foreach ([
            fn () => $this->service->contribute($fund->fresh(), $this->owner, '50.0000'),
            fn () => $this->service->proposeDisbursement($fund->fresh(), $this->owner, $this->beneficiary, '50.0000'),
            fn () => $this->service->approveDisbursement($pendingTx->fresh(), $this->owner),
        ] as $operation) {
            $blocked = false;
            try {
                $operation();
            } catch (\RuntimeException) {
                $blocked = true;
            }
            $this->assertTrue($blocked, 'يجب حجب العملية المالية عندما يكون الصندوق مجمّداً');
        }

        // الرصيد لم يتغير إطلاقاً أثناء التجميد
        $this->assertSame('400.0000', (string) $fund->fresh()->balance);
        $this->assertSame('pending_approval', $pendingTx->fresh()->status);

        // فك التجميد يعيد الصندوق للعمل الطبيعي
        $this->actingAs($admin, 'user')
            ->post(route('admin.amial.surface.funds.status', $fund->id), [
                'action' => 'unfreeze',
                'reason' => 'انتهت مراجعة الامتثال بنجاح',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('active', $fund->fresh()->status);
        $this->service->approveDisbursement($pendingTx->fresh(), $this->owner);
        $this->assertSame('300.0000', (string) $fund->fresh()->balance);
    }

    /** @test */
    public function direct_disbursement_cannot_drive_fund_balance_negative(): void
    {
        $fund = $this->createFundedPool('150.0000', false);

        $thrown = false;
        try {
            $this->service->proposeDisbursement($fund, $this->owner, $this->beneficiary, '200.0000', 'سحب زائد');
        } catch (\RuntimeException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame('150.0000', (string) $fund->fresh()->balance);
        $this->assertSame(
            '100.0000',
            (string) EMoney::where('user_id', $this->beneficiary->id)->value('current_balance')
        );
    }

    /** @test */
    public function admin_fund_detail_is_accessible_with_transactions_view_and_reconciles_accurately(): void
    {
        $fund = $this->createFundedPool('500.0000', true);
        $proposal = $this->service->proposeDisbursement($fund, $this->adminMember, $this->beneficiary, '125.0000', 'صرف معتمد');
        $this->service->approveDisbursement($proposal, $this->owner);

        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin, 'user')
            ->get(route('admin.amial.surface.funds.detail', $fund->id));

        $response->assertOk();
        $response->assertViewHas('gap', null);
        $response->assertViewHas('stored', '375.0000');
    }

    private function tierTwoCustomer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'type' => CUSTOMER_TYPE,
            'zone_code' => 'SOUTH',
            'kyc_tier' => 2,
            'is_phone_verified' => 1,
            'is_kyc_verified' => 1,
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
        ], $attributes));
    }
}
