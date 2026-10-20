<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>إنشاء حساب منشأة | أميال باي</title>
    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        *{box-sizing:border-box}body{margin:0;background:#f4f8f6;color:#15332c;font-family:Tahoma,"Segoe UI",sans-serif}
        .page{max-width:900px;margin:auto;padding:28px 16px 58px}.brand{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}
        h1{font-size:28px;margin:0 0 8px}.lead{color:#5d6e68;margin:0;line-height:1.7}.card{background:#fff;border:1px solid #dce8e2;border-radius:18px;padding:22px;box-shadow:0 12px 34px #163a2d0a}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.full{grid-column:1/-1}label{display:block;font-weight:700;font-size:14px}input,select{width:100%;margin-top:7px;padding:12px;border:1px solid #cbd9d2;border-radius:10px;background:#fff;font:inherit}
        input:focus,select:focus{outline:2px solid #1c8a66;border-color:#1c8a66}.hint,.sub{font-size:13px;line-height:1.65;color:#60736b}.policy{margin:18px 0;padding:15px;border-radius:12px;background:#f0f8f4;border:1px solid #cce6d8}.policy h3{margin:0 0 8px;font-size:16px}.policy ul{margin:8px 0 0;padding-right:20px;line-height:1.9}
        .actions{display:flex;gap:10px;align-items:center;margin-top:18px}.btn{border:0;border-radius:10px;padding:12px 17px;background:#126b50;color:#fff;font:inherit;font-weight:700;cursor:pointer}.btn:disabled{opacity:.55;cursor:not-allowed}.secondary{background:#eaf3ee;color:#17563f}.notice{margin:16px 0;padding:13px;border-radius:10px;background:#fff6dc;color:#725516;line-height:1.6}.error{background:#feeceb;color:#9d221d}.success{background:#e8f8ed;color:#17653b}.hidden{display:none}.back{color:#126b50;text-decoration:none;font-weight:700}
        @media(max-width:600px){.grid{grid-template-columns:1fr}.brand{align-items:flex-start;flex-direction:column}.actions{flex-direction:column;align-items:stretch}.btn{width:100%}}
    </style>
</head>
<body>
<main class="page">
    <div class="brand">
        <div><h1>إنشاء حساب منشأة</h1><p class="lead">اختر القطاع لتجهيز لوحة عمل منشأتك. التحقق والموافقة يديرهما موثّق بعد إرسال الطلب.</p></div>
        <a class="back" href="{{ route('merchant.web.login') }}">لدي حساب بالفعل ←</a>
    </div>

    <section id="form-card" class="card">
        <form id="registration-form" novalidate>
            <div class="grid">
                <label class="full">اسم المنشأة<input name="store_name" required minlength="2" maxlength="120" autocomplete="organization"></label>
                <label class="full">قطاع المنشأة
                    <select id="business-type" name="business_type" required>
                        <option value="">اختر القطاع…</option>
                        @foreach($businessTypes as $type)<option value="{{ $type['value'] }}">{{ $type['label'] }}</option>@endforeach
                    </select>
                </label>
                <label>اسم المالك<input name="given_name" required minlength="2" maxlength="60" autocomplete="given-name"></label>
                <label>اسم الأب<input name="father_name" required minlength="2" maxlength="60"></label>
                <label>اسم الجد<input name="grandfather_name" required minlength="2" maxlength="60"></label>
                <label>اسم العائلة<input name="family_name" required minlength="2" maxlength="80" autocomplete="family-name"></label>
                <label>رمز الدولة<input name="dial_country_code" value="+967" required maxlength="8" inputmode="tel" dir="ltr"></label>
                <label>رقم الهاتف<input name="phone" required maxlength="20" inputmode="tel" dir="ltr" autocomplete="tel"></label>
                <label class="full">البريد الإلكتروني<input id="email" name="email" type="email" required maxlength="255" dir="ltr" autocomplete="email"></label>
                <label>كلمة المرور<input name="password" type="password" required minlength="8" maxlength="64" autocomplete="new-password"></label>
                <label>تأكيد كلمة المرور<input name="password_confirmation" type="password" required minlength="8" maxlength="64" autocomplete="new-password"></label>
                <label>PIN مالي مستقل<input name="transaction_pin" type="password" required pattern="[0-9]{4}" inputmode="numeric" maxlength="4" autocomplete="new-password"></label>
                <label>تأكيد PIN<input id="pin-confirmation" type="password" required pattern="[0-9]{4}" inputmode="numeric" maxlength="4" autocomplete="new-password"></label>
                <label class="full"><input id="terms" type="checkbox" required style="width:auto;margin-left:8px"> أوافق على شروط أميال باي وسياسة الخصوصية.</label>
            </div>

            <div id="policy" class="policy hidden" aria-live="polite">
                <h3 id="policy-title">متطلبات موثّق بعد الإرسال</h3><p class="sub">القطاع يحدد اللوحة التشغيلية، وليس نوع وثائق مختلفاً.</p><ul id="requirements"></ul>
            </div>

            <div id="otp-section" class="hidden">
                <label>رمز البريد الإلكتروني<input id="email-otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label>
                <p class="hint">أرسلنا رمزاً من ستة أرقام إلى بريدك.</p>
            </div>
            <p id="status" class="notice hidden" role="alert"></p>
            <div class="actions">
                <button id="send-otp" type="button" class="btn">إرسال رمز البريد</button>
                <button id="submit" type="button" class="btn secondary hidden">تأكيد وإرسال الطلب إلى موثّق</button>
            </div>
        </form>
    </section>

    <section id="success-card" class="card hidden">
        <h2>تم إرسال طلب المنشأة إلى موثّق</h2>
        <p id="success-detail" class="lead"></p>
        <p class="hint">لن تُفعّل لوحة القطاع أو المحفظة قبل قرار الاعتماد.</p>
        <a class="back" href="{{ route('merchant.web.login') }}">العودة إلى تسجيل الدخول</a>
    </section>
</main>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const form=document.getElementById('registration-form'), type=document.getElementById('business-type');
    const policy=document.getElementById('policy'), requirements=document.getElementById('requirements');
    const status=document.getElementById('status'), otpSection=document.getElementById('otp-section');
    const otp=document.getElementById('email-otp'), send=document.getElementById('send-otp'), submit=document.getElementById('submit');
    let challengeId='', verificationToken='';

    const show=(message,kind='')=>{status.textContent=message;status.className='notice '+kind;};
    const errorMessage=(body)=>body?.message || body?.errors?.[0]?.message || 'تعذر إكمال العملية. تحقق من البيانات وحاول مرة أخرى.';
    const busy=(button,on)=>{button.disabled=on;};

    async function loadPolicy(){
        const sector=type.value; policy.classList.add('hidden');
        if(!sector)return;
        try{
            const r=await fetch('/api/v1/auth/account-opening/contract?account_type=merchant&business_type='+encodeURIComponent(sector),{headers:{Accept:'application/json'}});
            const b=await r.json(); if(!r.ok||!b.success)throw new Error(errorMessage(b));
            requirements.replaceChildren();
            Object.values(b.data.review_requirements||{}).forEach(label=>{const li=document.createElement('li');li.textContent=label;requirements.appendChild(li);});
            policy.classList.remove('hidden');
        }catch(e){show(e.message,'error');}
    }

    type.addEventListener('change',loadPolicy);
    send.addEventListener('click',async()=>{
        if(!form.reportValidity())return;
        if(form.elements.transaction_pin.value!==document.getElementById('pin-confirmation').value){show('تأكيد PIN غير مطابق.','error');return;}
        busy(send,true); show('جارٍ إرسال رمز البريد…');
        try{
            const r=await fetch('/api/v1/auth/email-otp/request',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({email:form.elements.email.value.trim(),purpose:'registration'})});
            const b=await r.json(); if(!r.ok||!b.success||!b.meta?.challenge_id)throw new Error(errorMessage(b));
            challengeId=b.meta.challenge_id; otpSection.classList.remove('hidden'); submit.classList.remove('hidden');
            show('أُرسل رمز التأكيد إلى البريد الإلكتروني.','success');
        }catch(e){show(e.message,'error');}finally{busy(send,false);}
    });
    submit.addEventListener('click',async()=>{
        if(!form.reportValidity()||!/^\d{6}$/.test(otp.value.trim())){show('أدخل رمز البريد المكوّن من 6 أرقام.','error');return;}
        if(form.elements.transaction_pin.value!==document.getElementById('pin-confirmation').value){show('تأكيد PIN غير مطابق.','error');return;}
        busy(submit,true); show('جارٍ تأكيد البريد…');
        try{
            const v=await fetch('/api/v1/auth/email-otp/verify',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({challenge_id:challengeId,email:form.elements.email.value.trim(),purpose:'registration',otp:otp.value.trim()})});
            const vb=await v.json(); if(!v.ok||!vb.success||!vb.meta?.verification_token)throw new Error(errorMessage(vb));
            verificationToken=vb.meta.verification_token;
            const data=new FormData(form); data.set('email_challenge_id',challengeId); data.set('email_verification_token',verificationToken); data.set('terms_accepted','1'); data.set('locale','ar');
            const r=await fetch('/api/v1/auth/register/merchant',{method:'POST',headers:{Accept:'application/json'},body:data});
            const b=await r.json(); if(!r.ok||!b.success)throw new Error(errorMessage(b));
            document.getElementById('form-card').classList.add('hidden'); document.getElementById('success-card').classList.remove('hidden');
            document.getElementById('success-detail').textContent='رقم منشأتك: '+(b.data?.merchant_number||'—')+' · تم تسجيل الطلب بنجاح.';
        }catch(e){show(e.message,'error');}finally{busy(submit,false);}
    });
})();
</script>
</body>
</html>
