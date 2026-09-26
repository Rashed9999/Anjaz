<?php

namespace Tests\Feature;

use App\Models\CreditCollection;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Merchant\MerchantPermissionService;
use App\Support\Merchant\MerchantPermissions as P;
use Laravel\Passport\Passport;
use App\Services\CashierShiftService;
use App\Services\CreditCollectionService;
use App\Services\CreditWalletCollectionService;
use App\Services\CustomerCreditService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Cash and Amial collections must share one debt ledger and never double-charge. */
class CreditCollectionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user=User::factory()->create(['type'=>3,'role'=>'merchant','zone_code'=>'SOUTH']);
        MerchantProfile::create([
            'user_id'=>$user->id,'verification_status'=>'verified',
            'business_type'=>A::BIZ_RETAIL,'subscription_plan'=>A::PLAN_BUSINESS,
        ]);
        EMoney::create([
            'user_id'=>$user->id,'current_balance'=>'500.0000',
            'held_balance'=>'0','pending_balance'=>'0','charge_earned'=>'0',
            'zone_code'=>'SOUTH',
        ]);
        return $user;
    }

    public function test_owner_cash_collection_reduces_debt_issues_receipt_without_wallet_credit(): void
    {
        $owner=$this->owner();
        $credit=app(CustomerCreditService::class);
        $account=$credit->findOrCreateAccount($owner->id,'+967771000001','عميل نقدي');
        $credit->recordSale($account,'3000',referenceNumber:'INV-1');
        $key='cash-collection-retry-2026';
        $service=app(CreditCollectionService::class);
        $item=$service->cash($owner,$owner,null,$account,'1200',$key);
        $this->assertSame('completed',$item->status);
        $this->assertSame('1800.0000',(string)$account->fresh()->current_balance);
        $this->assertSame('500.0000',(string)EMoney::where('user_id',$owner->id)->value('current_balance'));
        $receipt=Receipt::findOrFail($item->receipt_id);
        $this->assertSame('debt_payment',$receipt->receipt_type);
        $this->assertSame('cash',$receipt->metadata['payment_method']);
        $this->assertFalse($receipt->metadata['wallet_affected']);
        $this->assertNotEmpty($receipt->verification_code);
        $same=$service->cash($owner,$owner,null,$account,'1200',$key);
        $this->assertSame($item->id,$same->id);
        $this->assertSame('1800.0000',(string)$account->fresh()->current_balance);
        $this->assertSame(1,CreditCollection::where('account_id',$account->id)->count());
    }

    public function test_pos_cash_collection_appears_in_its_shift_without_counting_as_sales(): void
    {
        $owner=$this->owner();
        $worker=User::factory()->create(['type'=>4,'role'=>'pos','zone_code'=>'SOUTH']);
        $pos=PosUser::create([
            'user_id'=>$worker->id,'merchant_user_id'=>$owner->id,
            'pos_number'=>'TEST-POS-01','display_name'=>'الكاشير',
            'is_active'=>true,'permissions'=>[],
        ]);
        $shiftService=app(CashierShiftService::class);
        $shift=$shiftService->open($owner,$pos->id,'500');
        $credit=app(CustomerCreditService::class);
        $account=$credit->findOrCreateAccount($owner->id,'+967771000002','عميل الوردية');
        $credit->recordSale($account,'2000');
        $collection=app(CreditCollectionService::class)->cash(
            $owner,$worker,$pos->id,$account,'600','pos-cash-collection-2026');
        $this->assertSame($shift->id,$collection->cashier_shift_id);
        $this->assertSame($worker->id,$collection->actor_user_id);
        $snapshot=$shiftService->snapshot($shift);
        $this->assertSame('600.0000',$snapshot['cash_collections']);
        $this->assertSame('1100.0000',$snapshot['expected_cash']);
        $this->assertSame(0,$snapshot['sales_count']);
        $this->assertSame('1400.0000',(string)$account->fresh()->current_balance);
        $this->assertSame('500.0000',(string)EMoney::where('user_id',$owner->id)->value('current_balance'));
    }

    public function test_amial_request_does_not_change_wallet_or_credit_until_customer_pays(): void
    {
        $owner=$this->owner();
        $payer=User::factory()->create([
            'type'=>2,'phone'=>'+967771000003','zone_code'=>'SOUTH','is_active'=>1,
        ]);
        EMoney::create([
            'user_id'=>$payer->id,'current_balance'=>'4000.0000',
            'held_balance'=>'0','pending_balance'=>'0','charge_earned'=>'0',
            'zone_code'=>'SOUTH',
        ]);
        $credit=app(CustomerCreditService::class);
        $account=$credit->findOrCreateAccount($owner->id,$payer->phone,'عميل أميال');
        $credit->recordSale($account,'1700');
        $svc=app(CreditWalletCollectionService::class);
        $item=$svc->request($owner,$owner,null,$account,'1200','amial-credit-request-2026');
        $this->assertSame('pending',$item->status);
        $this->assertNotNull($item->payment_request_id);
        $this->assertSame($item->id,$svc->request(
            $owner,$owner,null,$account,'1200','amial-credit-request-2026')->id);
        $this->assertSame('1700.0000',(string)$account->fresh()->current_balance);
        $this->assertSame('4000.0000',(string)EMoney::where('user_id',$payer->id)->value('current_balance'));
        $this->assertSame('500.0000',(string)EMoney::where('user_id',$owner->id)->value('current_balance'));
        $this->assertDatabaseMissing('receipts',[
            'user_id'=>$owner->id,'reference_type'=>'customer_credit_account',
            'reference_id'=>$account->id,
        ]);
        $this->expectException(RuntimeException::class);
        $svc->confirm($owner,$owner,$item);
    }

    public function test_cashier_can_collect_debt_without_general_cash_powers_or_colleague_access(): void
    {
        $owner=$this->owner();
        $permissions=app(MerchantPermissionService::class);
        $roles=$permissions->seedRetailRoles($owner);
        $cashier=collect($roles)->first(fn($role)=>$role->code==='cashier');
        $this->assertNotNull($cashier);
        $pos=[];
        foreach ([1,2] as $n) {
            $worker=User::factory()->create(['type'=>4,'role'=>'pos','zone_code'=>'SOUTH']);
            $device=PosUser::create([
                'user_id'=>$worker->id,'merchant_user_id'=>$owner->id,
                'pos_number'=>'DEBT-TEST-POS-'.$n,'display_name'=>'نقطة '.$n,
                'is_active'=>true,'permissions'=>[],
            ]);
            $permissions->assign($owner,$worker,$cashier);
            app(CashierShiftService::class)->open($owner,$device->id,'0');
            $this->assertTrue($permissions->can($worker,P::DEBT_COLLECT));
            $this->assertFalse($permissions->can($worker,P::CASH_MOVE));
            $pos[]=[$worker,$device];
        }
        $credit=app(CustomerCreditService::class);
        $account=$credit->findOrCreateAccount($owner->id,'+967771008888','عميل الآجل');
        $credit->recordSale($account,'2000');
        Passport::actingAs($pos[0][0],[],'api');
        $url='/api/v1/amial/merchant/credit/customers/'.$account->id;
        $done=$this->postJson($url.'/payment',[
            'amount'=>'400','idempotency_key'=>'scope-cash-collection-key',
        ])->assertOk()->assertJsonPath('code','CASH_COLLECTED');
        $cashId=$done->json('meta.collection_id');
        $receiptId=$done->json('meta.receipt_id');
        $this->assertNotNull($receiptId);
        $this->postJson($url.'/payment',[
            'amount'=>'400','idempotency_key'=>'scope-cash-collection-key',
        ])->assertOk()->assertJsonPath('meta.collection_id',$cashId);
        $this->assertSame('1600.0000',(string)$account->fresh()->current_balance);
        $this->postJson($url.'/payment',['amount'=>'100'])->assertStatus(422);

        $payer=User::factory()->create(['type'=>2,'phone'=>'+967771009999','is_active'=>1,'zone_code'=>'SOUTH']);
        $pendingAccount=$credit->findOrCreateAccount($owner->id,$payer->phone,'عميل أميال');
        $credit->recordSale($pendingAccount,'500');
        $pending=app(CreditWalletCollectionService::class)->request(
            $owner,$pos[0][0],$pos[0][1]->id,$pendingAccount,'200','scope-wallet-collection-key');

        Passport::actingAs($pos[1][0],[],'api');
        $this->getJson('/api/v1/amial/merchant/credit/collections/pending')
            ->assertOk()->assertJsonCount(0,'meta.collections');
        $this->getJson('/api/v1/amial/merchant/credit/collections/'.$pending->id)
            ->assertNotFound();
        $this->postJson('/api/v1/amial/merchant/credit/collections/'.$pending->id.'/confirm',[])
            ->assertNotFound();
        $this->getJson('/api/v1/amial/merchant/credit/collections/'.$cashId.'/receipt')
            ->assertNotFound();

        Passport::actingAs($pos[0][0],[],'api');
        $this->getJson('/api/v1/amial/merchant/credit/collections/pending')
            ->assertOk()->assertJsonPath('meta.collections.0.account_id',$pendingAccount->id);
        Passport::actingAs($owner,[],'api');
        $this->getJson('/api/v1/amial/merchant/credit/collections/pending')
            ->assertOk()->assertJsonPath('meta.collections.0.collection_id',$pending->id);
    }
}
