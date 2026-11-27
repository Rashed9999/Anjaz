<?php

namespace Tests\Feature;

use App\Models\CharityCampaign;
use App\Models\CharityCategory;
use App\Models\CharityOrganization;
use App\Models\EMoney;
use App\Models\FamilyFund;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\DonationsService;
use App\Services\FamilyFundService;
use App\Services\PaymentRequestService;
use App\Services\PlatformRoleService;
use App\Services\SafePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\StartsWithSafePaymentIntakeOpen;
use Tests\TestCase;

/**
 * AMIAL-FOUR-FEATURES-002 — اختبارات تزامن حقيقية باتصالين مستقلين (MySQL + pcntl_fork).
 *
 * تثبت هذه الاختبارات تحت سباق متوازٍ حقيقي (عمليتان مستقلتان تتصلان بقاعدة البيانات بالتوازي) أن:
 * 1) اعتماد صرف الصندوق العائلي لا يُنفّذ مرتين أبداً.
 * 2) سباق الإلغاء الإداري مع الدفع على طلب المال ينتج حالة متسقة واحدة (إما paid وإما cancelled).
 * 3) استرداد التبرع بالتوازي لا يضاعف رصيد المتبرع.
 * 4) حسم نزاع الدفع الآمن بالتوازي لا يضاعف رصيد البائع.
 */
class FourFeaturesConcurrencyTest extends TestCase
{
    use StartsWithSafePaymentIntakeOpen;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:ing)?$/', DB::getDatabaseName());
        if (getenv('AMIAL_TEST_DISPOSABLE_DB') !== 'YES') {
            $this->markTestSkipped('Requires AMIAL_TEST_DISPOSABLE_DB=YES for a dedicated disposable MySQL database.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->startWithSafePaymentIntakeOpen();
        $this->beforeApplicationDestroyed(function () {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            RefreshDatabaseState::$migrated = true;
        });

        config(['amial.operational_governorates' => ['YE-AD']]);
        Queue::fake();
    }

    /** @test */
    public function concurrent_family_fund_disbursement_approval_never_double_spends(): void
    {
        $service = app(FamilyFundService::class);
        $owner = $this->tierTwoCustomer(['phone' => '+967709100001']);
        $adminMember = $this->tierTwoCustomer(['phone' => '+967709100002']);
        $beneficiary = $this->tierTwoCustomer(['phone' => '+967709100003']);

        EMoney::create(['user_id' => $owner->id, 'current_balance' => '1000.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $adminMember->id, 'current_balance' => '100.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $beneficiary->id, 'current_balance' => '0.0000', 'zone_code' => 'SOUTH']);

        $fund = $service->create($owner, 'صندوق التزامن', 'اختبار', requireOwnerApproval: true);
        $m1 = $service->inviteMember($fund, $owner, $adminMember->phone, 'admin');
        $service->acceptInvitation($m1, $adminMember);
        $m2 = $service->inviteMember($fund, $owner, $beneficiary->phone, 'member');
        $service->acceptInvitation($m2, $beneficiary);
        $service->contribute($fund->fresh(), $owner, '500.0000');

        $tx = $service->proposeDisbursement($fund->fresh(), $adminMember, $beneficiary, '200.0000', 'صرف متزامن');

        $results = $this->raceWorkers([
            function () use ($tx, $owner) {
                return app(FamilyFundService::class)->approveDisbursement($tx->fresh(), $owner->fresh());
            },
            function () use ($tx, $owner) {
                return app(FamilyFundService::class)->approveDisbursement($tx->fresh(), $owner->fresh());
            },
        ]);

        $approvedCount = count(array_filter(
            $results,
            fn ($r) => ($r['status'] ?? '') === 'ok' && ($r['value'] ?? false) === true
        ));
        $this->assertSame(1, $approvedCount, 'يجب أن ينجح عامل واحد فقط في اعتماد الصرف: ' . json_encode($results));
        $this->assertSame('300.0000', (string) FamilyFund::find($fund->id)->balance);
        $this->assertSame('200.0000', (string) EMoney::where('user_id', $beneficiary->id)->value('current_balance'));
    }

    /** @test */
    public function concurrent_admin_cancel_and_payer_pay_on_payment_request_are_mutually_exclusive(): void
    {
        $service = app(PaymentRequestService::class);
        $requester = User::factory()->create([
            'phone' => '+967709200001', 'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);
        $payer = User::factory()->create([
            'phone' => '+967709200002', 'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin', 'is_active' => 1]);
        app(PlatformRoleService::class)->assign($admin, PlatformRoleService::ADMIN);

        EMoney::create(['user_id' => $requester->id, 'current_balance' => '100.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $payer->id, 'current_balance' => '1000.0000', 'zone_code' => 'SOUTH']);

        $req = $service->create($requester, '250.0000', recipientPhone: $payer->phone);

        $results = $this->raceWorkers([
            function () use ($req, $payer) {
                app(PaymentRequestService::class)->pay($payer->fresh(), $req->fresh());
                return 'paid';
            },
            function () use ($req, $admin) {
                $resp = $this->actingAs($admin->fresh(), 'user')
                    ->post(route('admin.amial.surface.payment-requests.cancel', $req->id), [
                        'reason' => 'إلغاء إداري متزامن مع الدفع',
                    ]);
                return $resp->getSession()->has('success') ? 'cancelled' : 'cancel_rejected';
            },
        ]);

        $final = PaymentRequest::findOrFail($req->id);
        $payerBal = (string) EMoney::where('user_id', $payer->id)->value('current_balance');
        $reqBal = (string) EMoney::where('user_id', $requester->id)->value('current_balance');

        if ($final->status === 'paid') {
            $this->assertSame('750.0000', $payerBal, json_encode($results));
            $this->assertSame('350.0000', $reqBal, json_encode($results));
        } else {
            $this->assertSame('cancelled', $final->status, json_encode($results));
            $this->assertSame('1000.0000', $payerBal, json_encode($results));
            $this->assertSame('100.0000', $reqBal, json_encode($results));
        }
    }

    /** @test */
    public function concurrent_donation_refund_never_double_credits(): void
    {
        config()->set('amial.donations.fee_percent', '1.0');
        $donor = User::factory()->create([
            'phone' => '+967709300001', 'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin', 'is_active' => 1]);
        app(PlatformRoleService::class)->assign($admin, PlatformRoleService::ADMIN);
        EMoney::create(['user_id' => $donor->id, 'current_balance' => '1000.0000', 'zone_code' => 'SOUTH']);

        $cat = CharityCategory::updateOrCreate(['code' => 'race_food'], [
            'code' => 'race_food', 'name_ar' => 'إغاثة', 'sort_order' => 1, 'is_active' => true,
        ]);
        $org = CharityOrganization::create([
            'org_ulid' => (string) Str::ulid(), 'name_ar' => 'جمعية التزامن', 'license_number' => 'LIC-RACE-1',
            'description_ar' => 'اختبار', 'contact_phone' => '+967100009991',
            'verification_status' => 'verified', 'verified_at' => now(), 'is_active' => true, 'zone_code' => 'SOUTH',
        ]);
        $camp = CharityCampaign::create([
            'campaign_ulid' => (string) Str::ulid(), 'org_id' => $org->id, 'category_id' => $cat->id,
            'title_ar' => 'حملة تزامن', 'description_ar' => 'وصف', 'target_amount' => '5000.0000',
            'current_amount' => '0.0000', 'platform_fee_collected' => '0.0000', 'donor_count' => 0,
            'status' => 'active', 'start_at' => now()->subDay(), 'deadline_at' => now()->addMonth(), 'zone_code' => 'SOUTH',
        ]);

        $donation = app(DonationsService::class)->donate($donor, $camp, '200.0000');

        $results = $this->raceWorkers([
            function () use ($donation, $admin) {
                app(DonationsService::class)->refundDonation($donation->fresh(), $admin->fresh(), 'استرداد متزامن 1');
                return 'refunded';
            },
            function () use ($donation, $admin) {
                app(DonationsService::class)->refundDonation($donation->fresh(), $admin->fresh(), 'استرداد متزامن 2');
                return 'refunded';
            },
        ]);

        $okCount = count(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'ok'));
        $this->assertSame(1, $okCount, 'يجب أن ينجح استرداد واحد فقط: ' . json_encode($results));
        $this->assertSame('1000.0000', (string) EMoney::where('user_id', $donor->id)->value('current_balance'));
        $this->assertSame(0, CharityCampaign::find($camp->id)->donor_count);
    }

    /** @test */
    public function concurrent_safe_payment_admin_release_never_double_credits_seller(): void
    {
        $buyer = User::factory()->create([
            'phone' => '+967709400001', 'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);
        $seller = User::factory()->create([
            'phone' => '+967709400002', 'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);
        $admin1 = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin', 'is_active' => 1]);
        $admin2 = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin', 'is_active' => 1]);
        app(PlatformRoleService::class)->assign($admin1, PlatformRoleService::ADMIN);
        app(PlatformRoleService::class)->assign($admin2, PlatformRoleService::ADMIN);

        EMoney::create(['user_id' => $buyer->id, 'current_balance' => '20000.0000', 'held_balance' => '0.0000', 'pending_balance' => '0.0000', 'charge_earned' => '0.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $seller->id, 'current_balance' => '0.0000', 'held_balance' => '0.0000', 'pending_balance' => '0.0000', 'charge_earned' => '0.0000', 'zone_code' => 'SOUTH']);

        $svc = app(SafePaymentService::class);
        $payment = $svc->createAndFund($buyer, $seller, 'بضاعة تزامن', 'وصف', '5000.0000');
        $svc->sellerAccept($payment->fresh(), $seller);
        $svc->sellerMarkInDelivery($payment->fresh(), $seller);
        $svc->sellerMarkDelivered($payment->fresh(), $seller);
        $svc->buyerDispute($payment->fresh(), $buyer, 'نزاع تزامن', null, 'other');

        $results = $this->raceWorkers([
            function () use ($payment, $admin1) {
                app(SafePaymentService::class)->adminResolveRelease($payment->fresh(), $admin1->fresh(), 'حسم متزامن 1');
                return 'released';
            },
            function () use ($payment, $admin2) {
                app(SafePaymentService::class)->adminResolveRelease($payment->fresh(), $admin2->fresh(), 'حسم متزامن 2');
                return 'released';
            },
        ]);

        $okCount = count(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'ok'));
        $this->assertSame(1, $okCount, 'يجب أن ينجح قرار حسم واحد فقط: ' . json_encode($results));
        $sellerBal = (float) EMoney::where('user_id', $seller->id)->value('current_balance');
        $this->assertGreaterThan(0, $sellerBal);
        $this->assertLessThanOrEqual(5000.0, $sellerBal);
    }

    /**
     * يشغّل عاملَين متوازيَين عبر عمليتي OS مستقلتين (اتصالان مستقلان بقاعدة البيانات)
     * ويطلقان العمل في اللحظة نفسها عبر حاجز socket.
     *
     * @param  array<int, callable>  $tasks
     * @return array<int, array{status: string, value?: mixed, error?: string}>
     */
    private function raceWorkers(array $tasks): array
    {
        DB::purge();
        $workers = [];

        foreach ($tasks as $task) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $pid);

            if ($pid === 0) {
                fclose($parent);
                fread($child, 1);
                try {
                    DB::purge();
                    $val = $task();
                    fwrite($child, json_encode(['status' => 'ok', 'value' => $val]));
                } catch (\Throwable $e) {
                    fwrite($child, json_encode(['status' => 'error', 'error' => get_class($e) . ': ' . $e->getMessage()]));
                } finally {
                    fclose($child);
                    exit(0);
                }
            }

            fclose($child);
            $workers[] = [$pid, $parent];
        }

        // إطلاق جميع العمال في اللحظة نفسها
        foreach ($workers as [, $sock]) {
            fwrite($sock, 'G');
        }

        $results = [];
        foreach ($workers as [$pid, $sock]) {
            $raw = stream_get_contents($sock);
            fclose($sock);
            pcntl_waitpid($pid, $status);
            $results[] = json_decode($raw ?: '{}', true) ?: ['status' => 'empty'];
        }

        DB::purge();
        return $results;
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
