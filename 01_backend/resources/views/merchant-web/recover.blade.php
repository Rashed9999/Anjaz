<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>استعادة دخول التاجر | أميال باي</title>
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f2f7f5;color:#17372e;font-family:Tahoma,"Segoe UI",sans-serif}.card{width:min(94vw,520px);background:#fff;border:1px solid #dce7e3;border-radius:22px;padding:28px;box-shadow:0 24px 80px #123c3018}.brand{font-size:23px;font-weight:900;color:#12694c}.brand span{color:#d7a529}h1{font-size:23px;margin:26px 0 8px}.muted{font-size:12px;color:#6e827a;line-height:1.8}label{display:block;margin-top:16px;font-size:12px;font-weight:800}input{width:100%;height:48px;margin-top:7px;border:1px solid #cadbd4;border-radius:10px;padding:0 12px;font:14px Tahoma;direction:ltr}button{width:100%;border:0;border-radius:11px;padding:14px;margin-top:15px;font:700 14px Tahoma;cursor:pointer;background:#12694c;color:#fff}.secondary{background:#eaf3ef;color:#145d47}.status{display:none;margin-top:12px;padding:11px;border-radius:10px;font-size:12px}.status.show{display:block}.status.ok{background:#e9f7ef;color:#17613f}.status.err{background:#fff0ed;color:#96382d}.back{display:inline-block;margin-top:18px;color:#146a50;text-decoration:none;font-weight:700;font-size:13px}
</style></head><body>
<main class="card">
<div class="brand">أميال <span>باي</span> · الأعمال</div>
<h1>استعادة كلمة المرور</h1><p class="muted">الرمز يُرسل فقط إلى البريد الموثق المرتبط بالحساب. الدعم لا يرى الرمز ولا يحتاج نقله لك.</p>
<section id="request-step"><label>البريد الموثق<input id="email" type="email" autocomplete="email"></label><button id="send">إرسال رمز الاستعادة</button></section>
<section id="verify-step" hidden><label>رمز البريد<input id="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label><button id="verify" class="secondary">تأكيد الرمز</button></section>
<section id="password-step" hidden><label>كلمة المرور الجديدة<input id="password" type="password" minlength="4" maxlength="64" autocomplete="new-password"></label><label>تأكيد كلمة المرور<input id="confirm" type="password" minlength="4" maxlength="64" autocomplete="new-password"></label><button id="reset">حفظ كلمة المرور الجديدة</button></section>
<div id="status" class="status"></div>
<a class="back" href="{{ route('merchant.web.login') }}">← العودة إلى دخول التاجر</a>
</main>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
let challenge=null,token=null;const email=document.getElementById('email'),otp=document.getElementById('otp'),pass=document.getElementById('password'),confirm=document.getElementById('confirm'),box=document.getElementById('status');
const show=(m,ok=false)=>{box.textContent=m;box.className='status show '+(ok?'ok':'err')};
const message=b=>b?.message||(Array.isArray(b?.errors)&&b.errors[0]?.message)||'تعذر إتمام العملية.';
const post=async(url,p)=>{const r=await fetch(url,{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify(p)});let b=null;try{b=await r.json()}catch(_){}if(!r.ok)throw new Error(message(b));return b};
document.getElementById('send').onclick=async e=>{e.currentTarget.disabled=true;try{const value=email.value.trim().toLowerCase();if(!value)throw new Error('اكتب البريد الإلكتروني.');const b=await post('/api/v1/auth/email-otp/request',{email:value,purpose:'password_reset'});challenge=b?.meta?.challenge_id||null;document.getElementById('verify-step').hidden=false;otp.focus();show('إذا كان البريد مرتبطاً بحساب موثق فسيصلك رمز الاستعادة.',true)}catch(x){show(x.message)}finally{e.currentTarget.disabled=false}};
document.getElementById('verify').onclick=async e=>{e.currentTarget.disabled=true;try{const p={email:email.value.trim().toLowerCase(),purpose:'password_reset',otp:otp.value.trim()};if(challenge)p.challenge_id=challenge;const b=await post('/api/v1/auth/email-otp/verify',p);challenge=b?.meta?.challenge_id;token=b?.meta?.verification_token;if(!challenge||!token)throw new Error('تعذر إنشاء إثبات الاستعادة.');document.getElementById('password-step').hidden=false;pass.focus();show('تم التحقق من البريد. اختر كلمة مرور جديدة.',true)}catch(x){show(x.message)}finally{e.currentTarget.disabled=false}};
document.getElementById('reset').onclick=async e=>{e.currentTarget.disabled=true;try{if(pass.value.length<4)throw new Error('كلمة المرور قصيرة.');if(pass.value!==confirm.value)throw new Error('تأكيد كلمة المرور غير مطابق.');await post('/api/v1/auth/password-reset/email',{email:email.value.trim().toLowerCase(),challenge_id:challenge,verification_token:token,password:pass.value,password_confirmation:confirm.value});show('تم تغيير كلمة المرور. يمكنك الآن تسجيل الدخول.',true);document.getElementById('password-step').hidden=true}catch(x){show(x.message)}finally{e.currentTarget.disabled=false}};
})();
</script>
</body></html>
