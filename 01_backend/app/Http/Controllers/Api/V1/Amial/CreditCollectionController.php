<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\CreditCollection;
use App\Models\CustomerCreditAccount;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\Receipt;
use App\Models\User;
use App\Services\CreditCollectionService;
use App\Services\CreditWalletCollectionService;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\ReceiptDocumentService;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class CreditCollectionController extends Controller
{
    public function __construct(
        private readonly CreditCollectionService $cash,
        private readonly CreditWalletCollectionService $wallet,
        private readonly MerchantPermissionService $permissions,
    ) {}

    public function collectCash(Request $r,int $id): JsonResponse
    {
        if ($error=$this->validateInput($r)) return $error;
        if ($error=$this->check($r,(string)$r->input('amount'))) return $error;
        [$owner,$actor,$posId]=$this->actor($r);
        $a=CustomerCreditAccount::whereKey($id)->where('merchant_user_id',$owner->id)->first();
        if (!$a) return $this->error('NOT_FOUND','حساب الآجل غير موجود',404);
        try {
            $item=$this->cash->cash($owner,$actor,$posId,$a,
                (string)$r->input('amount'),(string)$r->input('idempotency_key'),
                $r->filled('sale_movement_ulid')?(string)$r->input('sale_movement_ulid'):null,
                $r->filled('note')?(string)$r->input('note'):null);
            return $this->ok($this->cash->result($item),'CASH_COLLECTED');
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->error('COLLECTION_REJECTED',$e->getMessage(),422);
        }
    }

    public function requestWallet(Request $r,int $id): JsonResponse
    {
        if ($error=$this->validateInput($r)) return $error;
        if ($error=$this->check($r,(string)$r->input('amount'))) return $error;
        [$owner,$actor,$posId]=$this->actor($r);
        $a=CustomerCreditAccount::whereKey($id)->where('merchant_user_id',$owner->id)->first();
        if (!$a) return $this->error('NOT_FOUND','حساب الآجل غير موجود',404);
        try {
            $item=$this->wallet->request($owner,$actor,$posId,$a,
                (string)$r->input('amount'),(string)$r->input('idempotency_key'),
                $r->filled('sale_movement_ulid')?(string)$r->input('sale_movement_ulid'):null);
            return $this->ok($this->wallet->details($item,$this->cash),'WALLET_REQUEST_CREATED');
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->error('REQUEST_REJECTED',$e->getMessage(),422);
        }
    }

    public function confirmWallet(Request $r,int $collection): JsonResponse
    {
        if ($error=$this->check($r)) return $error;
        [$owner,$actor,$posId]=$this->actor($r);
        $item=$this->scoped($owner,$actor,$posId)->whereKey($collection)->first();
        if (!$item) return $this->error('NOT_FOUND','طلب التحصيل غير موجود',404);
        if ($item->status==='review')
            return $this->error('REVIEW_REQUIRED','التحصيل تحت مراجعة الإدارة؛ لا تكرّر تسويته',409,
                $this->wallet->details($item,$this->cash));
        try {
            $done=$this->wallet->confirm($owner,$actor,$item);
            $data=$this->wallet->details($done,$this->cash);
            return $done->status==='completed'?$this->ok($data,'WALLET_COLLECTED')
                :$this->error('REVIEW_REQUIRED','وصل الدفع لكن الدين تغيّر؛ مطلوب تحقيق قبل التسوية',409,$data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->error('PAYMENT_PENDING',$e->getMessage(),409);
        }
    }

    public function list(Request $r): JsonResponse
    {
        if ($error=$this->check($r)) return $error;
        [$owner,$actor,$posId]=$this->actor($r);
        $items=$this->scoped($owner,$actor,$posId)
            ->whereIn('status',['pending','review'])->latest('id')->limit(50)->get();
        return $this->ok(['collections'=>$items->map(fn($c)=>$this->wallet->details($c,$this->cash))],
            'COLLECTIONS');
    }

    public function status(Request $r,int $collection): JsonResponse
    {
        if ($error=$this->check($r)) return $error;
        [$owner,$actor,$posId]=$this->actor($r);
        $item=$this->scoped($owner,$actor,$posId)->whereKey($collection)->first();
        return $item?$this->ok($this->wallet->details($item,$this->cash),'COLLECTION_STATUS')
            :$this->error('NOT_FOUND','التحصيل غير موجود',404);
    }

    public function receipt(Request $r,int $collection,ReceiptDocumentService $documents)
    {
        if ($error=$this->check($r)) return $error;
        [$owner,$actor,$posId]=$this->actor($r);
        $item=$this->scoped($owner,$actor,$posId)->whereKey($collection)
            ->where('status','completed')->first();
        if (!$item) return $this->error('NOT_FOUND','سند التحصيل غير موجود',404);
        if ($posId!==null && $item->actor_user_id!==$actor->id)
            return $this->error('FORBIDDEN','هذا السند يخص موظفاً آخر',403);
        $receipt=Receipt::whereKey($item->receipt_id)->where('user_id',$owner->id)->first();
        if (!$receipt) return $this->error('NOT_FOUND','لم يُصدر السند',404);
        $document=$documents->build($receipt);
        $qrDataUri=null;
        try {
            $svg=\SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(150)->margin(0)
                ->generate($document['verification_url']);
            $qrDataUri='data:image/svg+xml;base64,'.base64_encode($svg);
        } catch (\Throwable) {}
        $html=view($documents->a4View($document),compact('document','qrDataUri'))->render();
        $pdf=\App\Support\ArabicPdf::render($html,['format'=>'A4','margin'=>14]);
        return response($pdf,200,[
            'Content-Type'=>'application/pdf','Content-Length'=>(string)strlen($pdf),
            'Content-Disposition'=>'inline; filename="COLLECTION-'.$receipt->receipt_number.'.pdf"',
            'Cache-Control'=>'private, no-store',
        ]);
    }

    private function validateInput(Request $r): ?JsonResponse
    {
        $v=Validator::make($r->all(),[
            'amount'=>'required|numeric|gt:0',
            'idempotency_key'=>'required|string|min:12|max:128',
            'sale_movement_ulid'=>'sometimes|nullable|string|max:40',
            'note'=>'sometimes|nullable|string|max:255',
        ]);
        return $v->fails()?$this->error('VALIDATION',$v->errors()->first(),422):null;
    }

    private function check(Request $r,?string $amount=null): ?JsonResponse
    {
        [$owner,$actor]=$this->actor($r);
        if (!$owner || !$actor) return $this->error('FORBIDDEN','التحصيل للتاجر ونقطة البيع فقط',403);
        // A cashier may collect debt without receiving general cash-expense powers.
        // Older manager roles with CASH_MOVE remain compatible unless they
        // explicitly have a narrower DEBT_COLLECT grant.
        $permission=$this->permissions->can($actor,P::DEBT_COLLECT)
            ? P::DEBT_COLLECT : P::CASH_MOVE;
        try { $this->permissions->assert($actor,$permission,[],$amount); }
        catch (DomainException $e) { return $this->error('FORBIDDEN',$e->getMessage(),403); }
        return null;
    }

    /** A POS sees and confirms only its own collections, never a colleague's. */
    private function scoped(User $owner,User $actor,?int $posId): \Illuminate\Database\Eloquent\Builder
    {
        $q=CreditCollection::query()->where('merchant_user_id',$owner->id);
        if ($posId!==null) {
            $q->where('pos_user_id',$posId)->where('actor_user_id',$actor->id);
        }
        return $q;
    }

    private function actor(Request $r): array
    {
        $actor=$r->user();
        if (!$actor) return [null,null,null];
        if (MerchantProfile::where('user_id',$actor->id)->exists()) return [$actor,$actor,null];
        $pos=PosUser::where('user_id',$actor->id)->where('is_active',true)->first();
        if (!$pos) return [null,$actor,null];
        return [User::find($pos->merchant_user_id),$actor,$pos->id];
    }

    private function ok(array $meta,string $code): JsonResponse
    {
        return response()->json(['success'=>true,'code'=>$code,
            'message'=>'تم','errors'=>(object)[],'meta'=>$meta]);
    }

    private function error(string $code,string $message,int $status,array $meta=[]): JsonResponse
    {
        return response()->json(['success'=>false,'code'=>$code,
            'message'=>$message,'errors'=>(object)[],'meta'=>$meta?: (object)[]],$status);
    }
}
