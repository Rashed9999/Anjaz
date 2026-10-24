<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex,nofollow">
<title>تفعيل حساب التاجر | أميال باي</title>
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
*{box-sizing:border-box}body{margin:0;background:#f1f7f4;color:#183e31;font:15px Tahoma,"Segoe UI",sans-serif;min-height:100vh}
main{max-width:560px;margin:40px auto;padding:0 16px}
.card{background:white;border:1px solid #d9e9e1;border-radius:18px;padding:25px;box-shadow:0 12px 50px #0038220c;margin-bottom:14px}
h1{font-size:25px;margin:6px 0 12px}h2{font-size:18px}.muted{color:#607c70;line-height:1.8;font-size:13px}
label{display:block;margin-top:14px;font-weight:bold;font-size:13px}
input{display:block;width:100%;height:47px;padding:10px 12px;border-radius:9px;border:1px solid #cbded4;margin-top:6px;font:inherit}
button{background:#17694f;color:white;padding:13px;border:0;border-radius:9px;font:inherit;font-weight:bold;width:100%;cursor:pointer;margin-top:20px}
button:disabled{opacity:.5;cursor:wait}.hidden{display:none}.notice{padding:12px;border-radius:10px;background:#e8f5ec;margin:12px 0;line-height:1.8}.error{background:#ffeded;color:#a52922}
a{color:#14694d}.number{font-size:29px;letter-spacing:3px;direction:ltr;text-align:center;font-weight:900;padding:15px}
@media(max-width:600px){main{margin:12px auto}.card{padding:19px}}
</style>
</head>
<body>
<main>
<div class="card">
<h1>تفعيل حساب التاجر</h1>
<p class="muted">بعد اعتماد طلبك من موثّق، تحقق من بريدك الإلكتروني، واختر كلمة المرور ورمز PIN بنفسك. لا يستطيع موظف موثّق رؤيتهما ولا تعيينهما نيابة عنك.</p>
<section id="send">
<label>البريد الإلكتروني المسجل في موثّق<input type="email" id="email" required autocomplete="email" dir="ltr" maxlength="255"></label>
<button id="request" type="button">إرسال رمز التفعيل إلى البريد</button>
</section>
<section id="verify" class="hidden">
<label>رمز التحقق من البريد<input type="text" id="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr"></label>
<label>كلمة المرور الجديدة<input type="password" id="password" minlength="8" maxlength="72" autocomplete="new-password"></label>
<label>تأكيد كلمة المرور<input type="password" id="password-confirm" minlength="8" maxlength="72" autocomplete="new-password"></label>
<label>PIN للعمليات المالية (4 إلى 6 أرقام)<input type="password" id="pin" inputmode="numeric" pattern="[0-9]{4,6}" autocomplete="off"></label>
<label>تأكيد PIN<input type="password" id="pin-confirm" inputmode="numeric" pattern="[0-9]{4,6}" autocomplete="off"></label>
<button id="finish" type="button">تأكيد البريد وتفعيل الحساب</button>
</section>
<section id="done" class="hidden">
<div class="notice">تم تفعيل دخول التاجر. هذا هو رقم تاجرك، احتفظ به:</div>
<div class="number" id="merchant-number" dir="ltr"></div>
<a href="{{ route('merchant.web.login') }}">انتقل إلى صفحة دخول التاجر ←</a>
</section>
<p id="status" class="notice hidden" role="status" aria-live="polite"></p>
<p class="muted">هل ما زال الطلب قيد المراجعة؟ لا يمكن تفعيل الدخول حتى يصدر قرار الموافقة ويصل إلى أميال باي. إذا وصلتك الموافقة، فاستخدم البريد نفسه الذي قدمته عند الطلب.</p>
<a href="{{ route('merchant.web.login') }}">العودة إلى دخول التاجر</a>
</div>
</main>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
'use strict';
const id = n => document.getElementById(n), status=id('status');
const say=(t,error=false)=>{status.textContent=t;status.className='notice'+(error?' error':'')};
const send=async(url,data)=>{
const response=await fetch(url,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},cache:'no-store',body:JSON.stringify(data)});
const body=await response.json().catch(()=>({}));
if(!response.ok||body.success===false)throw Error(body.message||'تعذر تنفيذ الطلب.');
return body;
};
id('request').addEventListener('click',async()=>{
const button=id('request');button.disabled=true;
try{
const email=id('email').value.trim().toLowerCase();
if(!email)throw Error('أدخل البريد الإلكتروني.');
const result=await send('{{ route('merchant.web.activate.request') }}',{email});
id('verify').classList.remove('hidden');
say(result.message||'تحقق من بريدك إن كان الحساب معتمدًا.');
}catch(e){say(e.message,true)}finally{button.disabled=false}
});
id('finish').addEventListener('click',async()=>{
const button=id('finish');button.disabled=true;
try{
const email=id('email').value.trim().toLowerCase(),otp=id('otp').value.trim();
const password=id('password').value,pin=id('pin').value;
if(!/^[0-9]{6}$/.test(otp))throw Error('أدخل رمز البريد المكون من 6 أرقام.');
if(password.length<8||password!==id('password-confirm').value)throw Error('كلمة المرور قصيرة أو التأكيد غير مطابق.');
if(!/^[0-9]{4,6}$/.test(pin)||pin!==id('pin-confirm').value)throw Error('رمز PIN غير صالح أو التأكيد غير مطابق.');
const result=await send('{{ route('merchant.web.activate.complete') }}',{
email,otp,password,password_confirmation:password,pin,pin_confirmation:pin
});
id('password').value='';id('password-confirm').value='';id('pin').value='';id('pin-confirm').value='';
id('verify').classList.add('hidden');id('send').classList.add('hidden');id('done').classList.remove('hidden');
id('merchant-number').textContent=result.data?.merchant_number||'—';say(result.message||'تم التفعيل');
}catch(e){say(e.message,true)}finally{button.disabled=false}
});
})();
</script>
</body></html>
