<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>استكمال توثيق المنشأة | أميال باي</title>
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
*{box-sizing:border-box}body{margin:0;background:#f4f8f6;color:#15332c;font:15px Tahoma,"Segoe UI",sans-serif}
.page{max-width:780px;margin:auto;padding:26px 16px 70px}a{color:#17684f}h1{font-size:26px;margin:0 0 9px}h2{font-size:19px;margin:0 0 12px}
.card{background:#fff;border:1px solid #dbe8e1;border-radius:16px;padding:22px;margin:16px 0;box-shadow:0 8px 24px #193e2a0a}
p{line-height:1.8}.muted{color:#5c7169}.row{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.step{border:1px solid #d6e6dc;border-radius:12px;padding:14px;margin:12px 0}
label{font-weight:700;display:block;margin:10px 0}
input{width:100%;padding:12px;border:1px solid #bdcfc4;border-radius:9px;margin-top:7px;font:inherit}
.btn{border:0;background:#116c50;color:white;border-radius:9px;padding:11px 18px;font:inherit;cursor:pointer}
.btn:disabled{opacity:.5;cursor:wait}.notice{padding:13px;border-radius:10px;background:#edf5f1;line-height:1.8}
.error{background:#ffebe8;color:#a02b22}.success{background:#e6f7ee;color:#14633b}
.hidden{display:none}.tiny{font-size:13px}.link{background:none;border:0;color:#116c50;text-decoration:underline;cursor:pointer;font:inherit}
@media(max-width:600px){.card{padding:16px}.btn{width:100%}}
</style>
</head>
<body>
<main class="page">
<div class="row"><h1>استكمال توثيق المنشأة</h1><a href="{{ route('merchant.web.login') }}">تسجيل الدخول</a></div>
<p class="muted">ارفع مستندات منشأتك مرة واحدة، ثم راقب مراجعتها داخل موثّق. لا تُفتح المحفظة أو صلاحيات القطاع قبل الموافقة.</p>
<section class="card" id="resume-card">
<h2>العودة إلى طلب موجود</h2>
<p class="muted">لديك طلب سابق؟ أدخل بريد المالك وكلمة المرور لاستعادة جلسة استكمال آمنة. لا تُنشئ حسابًا جديدًا.</p>
<form id="resume-form">
<label>البريد الإلكتروني<input id="resume-email" type="email" required maxlength="255" autocomplete="email" dir="ltr"></label>
<label>كلمة المرور<input id="resume-password" type="password" required maxlength="200" autocomplete="current-password"></label>
<button type="submit" class="btn" id="resume-button">استعادة الطلب</button>
</form>
</section>
<section id="progress-card" class="card hidden">
<h2>حالة طلب المنشأة</h2>
<p class="muted">رقم الطلب: <strong id="case-number" dir="ltr"></strong></p>
<p id="state" class="notice" role="status"></p>
<p id="case-reason" class="muted hidden"></p>
<div id="documents">
<div class="step" data-kind="owner_identity">
<strong>1. هوية مالك المنشأة</strong>
<p class="muted tiny">صورة أو ملف PDF واضح للوثيقة المطلوبة. لا ترفعها إلا عبر هذه القناة الآمنة.</p>
<form class="evidence-form">
<input type="file" name="evidence" accept=".jpg,.jpeg,.png,.pdf" required>
<button type="submit" class="btn">رفع هوية المالك</button>
<span class="result tiny" aria-live="polite"></span>
</form>
</div>
<div class="step" data-kind="business_registration">
<strong>2. السجل أو الترخيص التجاري</strong>
<p class="muted tiny">مستند الترخيص المتعلق بنشاط المنشأة.</p>
<form class="evidence-form">
<input type="file" name="evidence" accept=".jpg,.jpeg,.png,.pdf" required>
<button type="submit" class="btn">رفع الترخيص</button>
<span class="result tiny" aria-live="polite"></span>
</form>
</div>
<div class="step" data-kind="operating_address">
<strong>3. إثبات موقع النشاط</strong>
<p class="muted tiny">وثيقة تثبت موقع المنشأة وعنوانها التشغيلي.</p>
<form class="evidence-form">
<input type="file" name="evidence" accept=".jpg,.jpeg,.png,.pdf" required>
<button type="submit" class="btn">رفع إثبات الموقع</button>
<span class="result tiny" aria-live="polite"></span>
</form>
</div>
</div>
<p id="step-note" class="notice">سيظهر هنا ما ينقص طلبك وما ينتظر مراجعة موثّق.</p>
<div class="row">
<button type="button" id="refresh" class="btn">تحديث الحالة</button>
<button type="button" id="forget" class="link">إنهاء جلسة الاستكمال على هذا الجهاز</button>
</div>
</section>
<p class="notice error hidden" id="error" role="alert"></p>
</main>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
'use strict';
const key = 'amial.merchant.opening.v1', api = '/api/v1/auth/account-opening/';
const resumeCard=document.getElementById('resume-card'), progressCard=document.getElementById('progress-card');
const error=document.getElementById('error'), state=document.getElementById('state');
let access=null;
const labels={submitted:'تم تقديم الطلب — استكمل المستندات المطلوبة',under_review:'الأدلة قيد مراجعة موثّق',needs_more_info:'طلب موثّق استكمال معلومات',awaiting_admin_approval:'وافق موثّق — بانتظار الاعتماد الإداري النهائي',approved:'تم اعتماد الحساب',rejected:'تم رفض طلب فتح الحساب'};
function notice(message){error.textContent=message||'';error.classList.toggle('hidden',!message)}
function forget(){sessionStorage.removeItem(key);access=null;progressCard.classList.add('hidden');resumeCard.classList.remove('hidden')}
function save(v){access={application_ulid:v.application_ulid,token:v.account_opening_access_token};sessionStorage.setItem(key,JSON.stringify(access));resumeCard.classList.add('hidden');progressCard.classList.remove('hidden')}
function message(body){if(typeof body?.message==='string')return body.message;return 'تعذر إكمال العملية. تأكد من الاتصال وحاول مجددًا.'}
async function response(r){const body=await r.json().catch(()=>({}));if(!r.ok||body.success===false)throw Error(message(body));return body}
function update(s){
document.getElementById('case-number').textContent=s.application_ulid||access?.application_ulid||'—';
state.textContent=labels[s.status]||'حالة الطلب: '+(s.status||'—');
const reason=document.getElementById('case-reason');reason.textContent=s.decision_reason||'';reason.classList.toggle('hidden',!s.decision_reason);
const received=new Map((s.evidence||[]).map(e=>[e.kind,e]));
const editable=['submitted','under_review','needs_more_info'].includes(s.status);
document.getElementById('documents').classList.toggle('hidden',!editable);
document.querySelectorAll('.step').forEach(step=>{
const e=received.get(step.dataset.kind), result=step.querySelector('.result');
result.textContent=!e?.document_received?' لم يُرفع المستند بعد':
e.delivered_to_muwathaq?' ✓ وصل الملف إلى موثّق للمراجعة':
' ✓ حُفظ الملف بأمان، وينتظر إرساله إلى موثّق';
step.querySelector('button').textContent=e?.document_received?'استبدال الملف':'رفع المستند';
});
const next=(s.next_steps||[]);document.getElementById('step-note').textContent=
s.status==='awaiting_admin_approval'?'أكمل موثّق المراجعة. انتظر قرار الإدارة النهائي.':
s.status==='approved'?'تم تفعيل الحساب بعد الاعتماد النهائي. يمكنك تسجيل الدخول.':
s.status==='rejected'?'هذا الطلب مرفوض. تواصل مع الدعم بشأن القرار.':
next.includes('await_muwathaq_review')?
((s.evidence||[]).some(e=>!e.delivered_to_muwathaq)?
'اكتملت الملفات وحُفظت بأمان. بعضها لا يزال ينتظر التسليم إلى موثّق، وستُعاد محاولة الإرسال.':
'وصلت الملفات إلى موثّق؛ انتظر المراجعة.'):
'الملفات الناقصة: '+next.filter(x=>x.startsWith('submit_')).length+' من أصل 3. ستظهر حالة كل ملف أعلاه.';
}
async function refresh(){
if(!access)return;
try{
const r=await fetch(api+encodeURIComponent(access.application_ulid)+'/status',{headers:{Accept:'application/json',Authorization:'Bearer '+access.token},cache:'no-store'});
if(r.status===403){forget();notice('انتهت صلاحية جلسة الاستكمال. استعد الطلب ببريدك وكلمة المرور.');return}
const body=await response(r);notice('');update(body.data);
}catch(e){notice(e.message)}
}
document.getElementById('resume-form').addEventListener('submit',async e=>{
e.preventDefault();notice('');const btn=document.getElementById('resume-button');btn.disabled=true;
try{
const r=await fetch('{{ route('merchant.web.opening.resume') }}',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({email:document.getElementById('resume-email').value,password:document.getElementById('resume-password').value}),cache:'no-store'});
const body=await response(r);document.getElementById('resume-password').value='';
if(!body.data?.account_opening_access_token){forget();notice(labels[body.data?.status]||'لا يمكن استكمال هذا الطلب بعد صدور القرار النهائي.');return}
save(body.data);await refresh();
}catch(e){notice(e.message)}finally{btn.disabled=false}
});
document.querySelectorAll('.evidence-form').forEach(form=>form.addEventListener('submit',async e=>{
e.preventDefault();if(!access)return;
const file=form.elements.evidence.files[0];if(!file)return;
if(file.size>10*1024*1024){notice('يجب ألا يتجاوز المستند 10 ميجابايت.');return}
const btn=form.querySelector('button'),data=new FormData();data.append('evidence',file);
btn.disabled=true;notice('');
try{
const kind=form.closest('.step').dataset.kind;
const r=await fetch(api+encodeURIComponent(access.application_ulid)+'/evidence/'+encodeURIComponent(kind),
{method:'POST',headers:{Accept:'application/json',Authorization:'Bearer '+access.token},body:data});
await response(r);form.reset();await refresh();
}catch(err){notice(err.message)}finally{btn.disabled=false}
}));
document.getElementById('refresh').addEventListener('click',refresh);
document.getElementById('forget').addEventListener('click',forget);
try{const existing=JSON.parse(sessionStorage.getItem(key)||'null');if(existing?.application_ulid&&existing?.token)save({application_ulid:existing.application_ulid,account_opening_access_token:existing.token})}catch(_){forget()}
if(access)refresh();
})();
</script>
</body>
</html>
