<?php
namespace App\Http\Controllers\Api\Internal;
use App\Http\Controllers\Controller;use App\Services\Muwathaq\MuwathaqProvisioningService;use DomainException;use Illuminate\Http\{JsonResponse,Request};
/** Signed server-to-server endpoint. Browsers and Amial UI cannot create an account through it. */
final class MuwathaqProvisioningController extends Controller { public function __construct(private readonly MuwathaqProvisioningService $provisioning){} public function store(Request $r):JsonResponse {try{$v=$this->provisioning->provisionApproved($r->json()->all());}catch(DomainException $e){return response()->json(['success'=>false,'code'=>$e->getMessage(),'message'=>'تعذر تهيئة الحساب المعتمد من موثّق.'],422);}return response()->json(['success'=>true,'code'=>$v['duplicate']?'MUWATHAQ_PROVISIONING_DUPLICATE':'MUWATHAQ_ACCOUNT_PROVISIONED','data'=>$v,'meta'=>(object)[]],$v['duplicate']?200:201);}}
