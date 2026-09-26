<?php

namespace App\Services;

use App\Models\CreditCollection;
use App\Models\CustomerCreditAccount;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * POS and web may REQUEST payment, never debit a customer.
 * A confirmed customer payment is applied ONCE to the existing credit ledger.
 */
class CreditWalletCollectionService
{
    public function __construct(
        private readonly PaymentRequestService $requests,
        private readonly MerchantPaymentReferenceService $references,
        private readonly CustomerCreditService $credit,
        private readonly CreditSourceSettlementService $sources,
        private readonly ReceiptService $receipts,
    ) {}

    public function request(User $merchant,User $actor,?int $posId,CustomerCreditAccount $account,
        string $amount,string $key,?string $saleUlid=null): CreditCollection
    {
        $amount=MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) throw new InvalidArgumentException('المبلغ يجب أن يكون موجباً');
        return DB::transaction(function() use ($merchant,$actor,$posId,$account,$amount,$key,$saleUlid) {
            $a=CustomerCreditAccount::whereKey($account->id)
                ->where('merchant_user_id',$merchant->id)->lockForUpdate()->firstOrFail();
            $old=CreditCollection::where('merchant_user_id',$merchant->id)
                ->where('idempotency_key',$key)->first();
            if ($old) {
                if ($old->account_id!==$a->id || $old->payment_method!=='amial_pay'
                    || MoneyService::compare((string)$old->amount,$amount)!==0
                    || $old->sale_movement_ulid!==$saleUlid)
                    throw new InvalidArgumentException('مفتاح المحاولة مستخدم لتحصيل آخر');
                return $old;
            }
            if (MoneyService::compare($amount,(string)$a->current_balance)>0)
                throw new RuntimeException('المبلغ أكبر من الدين الحالي');
            $payer=$a->customer_user_id?User::find($a->customer_user_id):null;
            if (!$payer || !$payer->is_active)
                throw new RuntimeException('تحصيل أميال يتطلب ربط العميل بحساب أميال نشط');
            if ($saleUlid!==null) $this->sources->allocate($a,$amount,$saleUlid);
            $request=$this->requests->create(
                requester:$merchant,amount:$amount,recipientPhone:$payer->phone,
                recipientName:$a->customer_name,note:'سداد دين آجل',
                shareMethod:PaymentRequest::SHARE_QR
            );
            if ((int)$request->recipient_user_id!==(int)$payer->id)
                throw new RuntimeException('لم يطابق طلب الدفع صاحب حساب الآجل');
            return CreditCollection::create([
                'collection_ulid'=>(string)Str::ulid(),
                'merchant_user_id'=>$merchant->id,'account_id'=>$a->id,
                'actor_user_id'=>$actor->id,'pos_user_id'=>$posId,
                'payment_method'=>'amial_pay','status'=>'pending','amount'=>$amount,
                'idempotency_key'=>$key,'payment_request_id'=>$request->id,
                'sale_movement_ulid'=>$saleUlid,
            ]);
        },3);
    }

    public function confirm(User $merchant,User $actor,CreditCollection $item): CreditCollection
    {
        return DB::transaction(function() use ($merchant,$actor,$item) {
            $c=CreditCollection::whereKey($item->id)
                ->where('merchant_user_id',$merchant->id)->lockForUpdate()->firstOrFail();
            if ($c->status==='completed') return $c;
            if ($c->payment_method!=='amial_pay' || !$c->payment_request_id)
                throw new InvalidArgumentException('الطلب ليس تحصيلاً عبر أميال');
            $a=CustomerCreditAccount::whereKey($c->account_id)
                ->where('merchant_user_id',$merchant->id)->lockForUpdate()->firstOrFail();
            $payment=PaymentRequest::whereKey($c->payment_request_id)->lockForUpdate()->firstOrFail();
            if ($payment->status!=='paid' || !$payment->paid_transaction_id)
                throw new RuntimeException('بانتظار دفع العميل عبر التطبيق');
            if ((int)$payment->paid_by_user_id!==(int)$a->customer_user_id)
                throw new RuntimeException('الدافع غير صاحب حساب الآجل');
            $proof=$this->references->assertPaidForMerchant(
                $merchant,$payment->paid_transaction_id,(string)$c->amount);
            if ($proof->id!==$payment->id) throw new RuntimeException('مرجع الدفع لا يخص هذا الطلب');
            $amount=(string)$c->amount;
            if (MoneyService::compare($amount,(string)$a->current_balance)>0) {
                // Money already arrived: cannot silently double-collect or roll it back.
                $c->update(['status'=>'review','note'=>'دفع العميل مؤكّد لكن الدين تغيّر قبل إقفال التحصيل؛ راجع الاسترداد']);
                return $c->fresh();
            }
            $alloc=$this->sources->allocate($a,$amount,$c->sale_movement_ulid);
            $movement=$this->credit->recordPayment(
                account:$a,amount:$amount,note:'سداد آجل عبر أميال',
                createdBy:$actor->id,referenceType:'debt_payment',
                referenceId:$payment->paid_transaction_id,referenceNumber:$payment->short_code,
                saleMovementUlid:$c->sale_movement_ulid);
            $payer=User::findOrFail($a->customer_user_id);
            $this->sources->apply($alloc,$movement,$payer);
            // The payment request already moved wallet funds; voucher only.
            [, $receipt]=$this->receipts->issueDualForTransfer([
                'from_user_id'=>$payer->id,'to_user_id'=>$merchant->id,
                'reference_transaction_id'=>$payment->paid_transaction_id,
                'receipt_type'=>'debt_payment','amount'=>$amount,'fee'=>'0',
                'reference_type'=>'customer_credit_account','reference_id'=>$a->id,
                'metadata'=>[
                    'payment_method'=>'amial_pay','credit_movement_id'=>$movement->id,
                    'collection_ulid'=>$c->collection_ulid,
                    'customer_name'=>$a->customer_name,'customer_phone'=>$a->customer_phone,
                    'balance_after'=>(string)$a->fresh()->current_balance,
                    'teller_name'=>trim(($actor->f_name??'').' '.($actor->l_name??'')),
                    'note'=>'سداد دين آجل عبر أميال باي',
                ],'zone_code'=>$a->zone_code?:'SOUTH',
            ]);
            $c->update([
                'status'=>'completed','paid_transaction_id'=>$payment->paid_transaction_id,
                'credit_movement_id'=>$movement->id,'receipt_id'=>$receipt->id,
            ]);
            return $c->fresh();
        },3);
    }

    public function details(CreditCollection $c,CreditCollectionService $cash): array
    {
        $payment=$c->payment_request_id?PaymentRequest::find($c->payment_request_id):null;
        return $cash->result($c)+[
            'payment_code'=>$payment?->short_code,
            'payment_url'=>$payment?->publicUrl(),
            'payment_state'=>$payment?->status,
            'needs_review'=>$c->status==='review',
        ];
    }
}
