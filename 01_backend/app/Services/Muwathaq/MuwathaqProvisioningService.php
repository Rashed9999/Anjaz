<?php
namespace App\Services\Muwathaq;
use App\Models\{AgentProfile,EMoney,Merchant,MerchantProfile,MuwathaqAccountOpeningApplication,User};
use App\Services\{AuditService,Merchant\MerchantNumberService,Vertical\VerticalBootstrapService,ZoneAssignmentService};
use App\Support\Phone;
use DomainException;
use Illuminate\Support\Facades\{DB,Hash,Schema};
use Illuminate\Support\Str;
/** The only account-creation consumer: signed Muwathaq approval becomes Amial operational data. */
final class MuwathaqProvisioningService {
 public function provisionApproved(array $e): array {
  $this->valid($e);
  return DB::transaction(function() use($e) {
   $existing=MuwathaqAccountOpeningApplication::query()->where('application_ulid',$e['application_ulid'])->lockForUpdate()->first();
   if($existing) return ['user_id'=>$existing->user_id,'duplicate'=>true,'access_delivery'=>'password_reset_required'];
   $a=$e['applicant']; $phone=Phone::canonical((string)$a['phone'],(string)($a['dial_country_code']??'')); $email=mb_strtolower(trim((string)$a['email']));
   if(User::query()->whereIn('phone',Phone::variants($phone))->lockForUpdate()->exists()||User::query()->whereRaw('LOWER(email)=?',[$email])->lockForUpdate()->exists()) throw new DomainException('MUWATHAQ_IDENTITY_ALREADY_EXISTS');
   $kind=(string)$e['subject_kind']; $u=new User; $u->f_name=trim((string)$a['f_name']); $u->l_name=trim((string)$a['l_name']); $u->family_name=$u->l_name;
   $u->declared_legal_name=trim((string)($a['legal_name']??trim($u->f_name.' '.$u->l_name))); $u->legal_name_status='verified'; $u->legal_name_verified_at=now();
   $u->phone=$phone; $u->dial_country_code=(string)($a['dial_country_code']??''); $u->email=$email; $u->gender=$kind==='customer'?(string)($a['gender']??''):null;
   $u->type=match($kind){'agent'=>AGENT_TYPE,'merchant'=>MERCHANT_TYPE,default=>CUSTOMER_TYPE}; $u->password=Hash::make(Str::random(64)); $u->is_active=0; $u->is_phone_verified=0; $u->is_email_verified=0; $u->is_kyc_verified=0; $u->kyc_tier=0; $u->zone_code=ZoneAssignmentService::ZONE_UNKNOWN; if(Schema::hasColumn('users','requires_pin_setup'))$u->requires_pin_setup=true; $u->save();
   $s=(array)($e['subject']??[]);
   if($kind==='merchant'){ $m=Merchant::firstOrCreate(['user_id'=>$u->id],['store_name'=>trim((string)($s['store_name']??'')),'address'=>trim((string)($s['operating_address']??''))?:'—']); if(trim((string)$m->merchant_number)==='')app(MerchantNumberService::class)->assignTo($m); MerchantProfile::firstOrCreate(['user_id'=>$u->id],['business_type'=>(string)$e['business_type'],'verification_status'=>'unverified','zone_code'=>ZoneAssignmentService::ZONE_UNKNOWN,'subscription_plan'=>\App\Support\Access\AccessConstants::PLAN_FREE,'subscription_expires_at'=>now()->addDays(30),'daily_receive_limit'=>'5000000','single_receive_limit'=>'1000000','monthly_receive_limit'=>'50000000','can_transfer_out'=>true]); app(VerticalBootstrapService::class)->ensureFor($u); }
   if($kind==='agent'){ AgentProfile::firstOrCreate(['user_id'=>$u->id],['business_name'=>trim((string)($s['store_name']??'')),'location_address'=>trim((string)($s['operating_address']??'')),'status'=>'pending_approval','zone_code'=>ZoneAssignmentService::ZONE_UNKNOWN]); if(Schema::hasColumn('users','agent_number')){$u->agent_number=sprintf('AG-%03d',User::query()->where('type',AGENT_TYPE)->count());$u->save();} }
   $app=MuwathaqAccountOpeningApplication::query()->create(['application_ulid'=>$e['application_ulid'],'user_id'=>$u->id,'origin'=>'muwathaq','subject_kind'=>$kind,'verification_path'=>$e['verification_path'],'business_type'=>$e['business_type']??null,'requested_tier'=>$e['requested_tier']??null,'status'=>MuwathaqAccountOpeningApplication::STATUS_APPROVED,'submitted_at'=>now(),'decided_at'=>now(),'muwathaq_decided_at'=>now(),'decision_reference'=>$e['decision_reference'],'decision_context'=>$this->context((array)$e['decision_context']),'evidence_completed_at'=>now()]);
   $this->activate($u,$app); EMoney::query()->firstOrCreate(['user_id'=>$u->id,'currency'=>\App\Support\Money\Currencies::BASE]);
   app(AuditService::class)->record(['actor_type'=>'system','actor_user_id'=>null,'subject_type'=>'user','subject_id'=>$u->id,'action'=>'MUWATHAQ_ACCOUNT_PROVISIONED','decision_code'=>'MUWATHAQ_APPROVED','context'=>['application_ulid'=>$e['application_ulid'],'subject_kind'=>$kind,'decision_reference'=>$e['decision_reference']],'severity'=>'info']);
   if($kind==='merchant') app(\App\Services\Merchant\MerchantWorkspaceOutbox::class)->recordFor($u);
   return ['user_id'=>$u->id,'duplicate'=>false,'access_delivery'=>'password_reset_required'];
  });
 }
 private function activate(User $u,MuwathaqAccountOpeningApplication $a):void { $c=(array)$a->decision_context;$g=\App\Support\YemenGovernorates::codeFromName((string)($c['verified_residence_governorate']??''));if($g===null)throw new DomainException('MUWATHAQ_GOVERNORATE_REQUIRED');$u->is_phone_verified=!empty($c['verified_phone'])?1:0;$u->residence_governorate=$g;$u->verified_residence_governorate=$g;$u->residence_verified_at=now();$z=app(ZoneAssignmentService::class)->assignFromKyc($u,$g);
  if($a->subject_kind==='customer'){if($a->verification_path===MuwathaqAccountOpeningService::PATH_WOMEN_PROGRESSIVE)$u->kyc_tier=max(1,min(3,(int)($a->requested_tier?:1)));else{if(empty($c['verified_identity']))throw new DomainException('MUWATHAQ_IDENTITY_REQUIRED');$u->is_kyc_verified=1;$u->kyc_tier=3;}$u->is_active=1;$u->save();return;}
  if($a->subject_kind==='merchant'){if(empty($c['verified_owner_identity'])||empty($c['verified_business_requirements']))throw new DomainException('MUWATHAQ_MERCHANT_REQUIREMENTS_REQUIRED');$p=MerchantProfile::query()->where('user_id',$u->id)->lockForUpdate()->firstOrFail();$p->verification_status='verified';$p->verified_at=now();$p->zone_code=$z;$p->save();$u->is_kyc_verified=1;$u->is_active=1;$u->save();return;}
  if(empty($c['admin_provisioned'])||empty($c['verified_owner_identity']))throw new DomainException('MUWATHAQ_AGENT_REQUIREMENTS_REQUIRED');$p=AgentProfile::query()->where('user_id',$u->id)->lockForUpdate()->firstOrFail();$p->status='active';$p->zone_code=$z;$p->save();$u->is_kyc_verified=1;$u->is_active=1;$u->save();
 }
 private function valid(array $e):void {foreach(['event_version','event_type','application_ulid','decision_reference','subject_kind','verification_path','applicant','decision_context']as$k)if(!array_key_exists($k,$e))throw new DomainException('MUWATHAQ_PROVISIONING_INVALID');if($e['subject_kind']==='merchant' && !in_array((string)($e['business_type']??''), \App\Domain\Verticals\VerticalRegistry::codes(), true)) throw new DomainException('MUWATHAQ_BUSINESS_TYPE_INVALID');if((int)$e['event_version']!==1||$e['event_type']!=='account.approved'||!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/',(string)$e['application_ulid'])||!preg_match('/^[A-Za-z0-9_-]{8,100}$/',(string)$e['decision_reference'])||!in_array($e['subject_kind'],['customer','merchant','agent'],true)||!is_array($e['applicant'])||!is_array($e['decision_context']))throw new DomainException('MUWATHAQ_PROVISIONING_INVALID');foreach(['f_name','l_name','phone','email']as$k)if(trim((string)($e['applicant'][$k]??''))==='')throw new DomainException('MUWATHAQ_APPLICANT_INCOMPLETE');}
 private function context(array $c):array{return collect($c)->only(['policy_version','verified_phone','verified_residence_governorate','verified_identity','verified_ownership_assurance','verified_owner_identity','verified_business_requirements','admin_provisioned'])->filter(fn($v)=>is_scalar($v))->all();}
}
