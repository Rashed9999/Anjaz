<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CreditCollection;
use App\Models\CustomerCreditAccount;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cash collections do not credit e_money and do not create a second debt ledger.
 * A POS employee must have an open, attributed cash drawer.
 */
class CreditCollectionService
{
    public function __construct(
        private readonly CustomerCreditService $credit,
        private readonly CreditSourceSettlementService $sources,
        private readonly CashierShiftService $shifts,
        private readonly ReceiptService $receipts,
    ) {}

    public function cash(User $merchant, User $actor, ?int $posId, CustomerCreditAccount $account,
        string $amount, string $idempotencyKey, ?string $saleMovementUlid=null,
        ?string $note=null): CreditCollection
    {
        $amount=MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) throw new InvalidArgumentException('المبلغ يجب أن يكون موجباً');

        return DB::transaction(function() use ($merchant,$actor,$posId,$account,$amount,$idempotencyKey,$saleMovementUlid,$note) {
            $existing=$this->existing($merchant->id,$idempotencyKey);
            if ($existing) return $this->replay($existing,$account,$amount,$saleMovementUlid);
            $locked=CustomerCreditAccount::whereKey($account->id)
                ->where('merchant_user_id',$merchant->id)->lockForUpdate()->firstOrFail();
            // A second request with the same key may have finished while we
            // waited for the account lock.
            $existing=$this->existing($merchant->id,$idempotencyKey);
            if ($existing) return $this->replay($existing,$locked,$amount,$saleMovementUlid);
            if (MoneyService::compare($amount,(string)$locked->current_balance)>0)
                throw new RuntimeException('المبلغ أكبر من الدين المستحق');

            $shift=$posId===null?null:$this->shifts->current($merchant,$posId);
            if ($posId!==null && !$shift) throw new RuntimeException('افتح وردية نقطة البيع قبل التحصيل النقدي');
            if ($shift) {
                $shift=CashierShift::whereKey($shift->id)->where('status','open')->lockForUpdate()->first();
                if (!$shift) throw new RuntimeException('هذه الوردية أُغلقت');
            }
            $allocations=$this->sources->allocate($locked,$amount,$saleMovementUlid);
            $collection=CreditCollection::create([
                'collection_ulid'=>(string)Str::ulid(),'merchant_user_id'=>$merchant->id,
                'account_id'=>$locked->id,'actor_user_id'=>$actor->id,'pos_user_id'=>$posId,
                'cashier_shift_id'=>$shift?->id,'payment_method'=>'cash',
                'status'=>'pending','amount'=>$amount,'idempotency_key'=>$idempotencyKey,
                'sale_movement_ulid'=>$saleMovementUlid,'note'=>$note,
            ]);
            $movement=$this->credit->recordPayment(
                account:$locked,amount:$amount,note:$note?:'تحصيل دين نقدي',
                createdBy:$actor->id,referenceType:'cash_debt_collection',
                referenceId:$collection->collection_ulid,saleMovementUlid:$saleMovementUlid
            );
            $this->sources->apply($allocations,$movement,$actor);
            $receipt=$this->receipts->issueCredit([
                'user_id'=>$merchant->id,'counterparty_user_id'=>$locked->customer_user_id,
                'reference_transaction_id'=>'CASH-'.$collection->collection_ulid,
                'receipt_type'=>'debt_payment','amount'=>$amount,'fee'=>'0',
                'reference_type'=>'customer_credit_account','reference_id'=>$locked->id,
                'metadata'=>[
                    'payment_method'=>'cash','wallet_affected'=>false,
                    'collection_ulid'=>$collection->collection_ulid,
                    'credit_movement_id'=>$movement->id,
                    'customer_name'=>$locked->customer_name,'customer_phone'=>$locked->customer_phone,
                    'teller_name'=>trim(($actor->f_name??'').' '.($actor->l_name??'')),
                    'shift_id'=>$shift?->id,
                    'balance_after'=>(string)$locked->fresh()->current_balance,
                    'note'=>$note?:'سداد آجل نقداً',
                ],
                'zone_code'=>$locked->zone_code?:'SOUTH',
            ]);
            $movement->update(['reference_number'=>$receipt->receipt_number]);
            $collection->update([
                'credit_movement_id'=>$movement->id,
                'receipt_id'=>$receipt->id,'status'=>'completed',
            ]);
            return $collection->fresh();
        },3);
    }

    public function result(CreditCollection $c): array
    {
        $receipt=$c->receipt_id?Receipt::find($c->receipt_id):null;
        return [
            'collection_id'=>$c->id,'collection_ref'=>$c->collection_ulid,
            'account_id'=>$c->account_id,
            'status'=>$c->status,'payment_method'=>$c->payment_method,
            'paid'=>(string)$c->amount,
            'new_balance'=>(string)CustomerCreditAccount::findOrFail($c->account_id)->current_balance,
            'receipt_id'=>$receipt?->id,'receipt_number'=>$receipt?->receipt_number,
            'verification_url'=>$receipt?rtrim((string)config('app.url'),'/').'/v/'.$receipt->verification_code:null,
        ];
    }

    private function existing(int $merchant,string $key): ?CreditCollection
    {
        return CreditCollection::where('merchant_user_id',$merchant)
            ->where('idempotency_key',$key)->lockForUpdate()->first();
    }

    private function replay(CreditCollection $c,CustomerCreditAccount $a,string $amount,?string $sale): CreditCollection
    {
        if ($c->account_id!==$a->id || $c->payment_method!=='cash'
            || MoneyService::compare((string)$c->amount,$amount)!==0
            || $c->sale_movement_ulid!==$sale) {
            throw new InvalidArgumentException('مفتاح إعادة المحاولة مستخدم لتحصيل مختلف');
        }
        return $c;
    }
}
