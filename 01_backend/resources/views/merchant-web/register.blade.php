<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>إنشاء حساب منشأة | أميال باي</title>
    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        *{box-sizing:border-box}body{margin:0;background:#f4f8f6;color:#15332c;font-family:Tahoma,"Segoe UI",sans-serif}
        .page{max-width:1080px;margin:auto;padding:28px 18px 60px}.brand{font-size:25px;font-weight:900;color:#116c50}.brand span{color:#d7a529}.brand small{font-size:12px;font-weight:500;color:#647a72}
        .hero{display:flex;justify-content:space-between;gap:18px;align-items:end;margin:18px 0 22px}.hero h1{margin:0 0 8px;font-size:27px}.hero p{margin:0;color:#627870;line-height:1.8}.back{color:#146a50;text-decoration:none;font-weight:700}
        .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:18px}.step{padding:11px 12px;border-radius:12px;background:#e8f1ed;color:#6a7d76;font-size:12px;font-weight:700}.step.active{background:#12694c;color:#fff}
        .card{background:#fff;border:1px solid #dbe7e2;border-radius:22px;box-shadow:0 16px 50px #123c3010;padding:24px;margin-bottom:16px}
        .card h2{font-size:18px;margin:0 0 5px}.sub{color:#71827c;font-size:12px;line-height:1.75;margin-bottom:18px}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.full{grid-column:1/-1}
        label{display:block;font-size:12px;font-weight:800;color:#405e54}input,select{width:100%;height:48px;margin-top:7px;border:1px solid #cbdad4;border-radius:11px;background:#fff;padding:0 12px;font:14px Tahoma;color:#17382f}input:focus,select:focus{outline:3px solid #1e82601a;border-color:#228061}
        input[type=file]{padding:10px;height:auto;background:#f8fbfa}.hint{font-size:11px;color:#74847f;margin-top:5px;line-height:1.6}
        .email-box{border:1px solid #d6e5df;background:#f7fbf9;border-radius:15px;padding:16px}.row{display:flex;gap:10px;align-items:end}.row .grow{flex:1}
        button{border:0;border-radius:11px;padding:13px 17px;font:700 14px Tahoma;cursor:pointer}.primary{background:#12694c;color:#fff}.secondary{background:#eaf3ef;color:#165b46}.primary:disabled,.secondary:disabled{opacity:.55;cursor:not-allowed}
        .status{display:none;margin-top:12px;padding:11px 13px;border-radius:10px;font-size:12px;line-height:1.7}.status.show{display:block}.status.ok{background:#eaf7f0;color:#17613f}.status.err{background:#fff0ed;color:#9b382c}
        .docs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.agree{display:flex;gap:9px;align-items:flex-start;margin-top:16px;font-weight:500;line-height:1.7}.agree input{width:17px;height:17px;margin:3px 0 0}
        .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}.submit{min-width:210px}
        .success{display:none;text-align:center;padding:34px}.success.show{display:block}.success .checkmark{width:68px;height:68px;border-radius:50%;display:grid;place-items:center;margin:0 auto 14px;background:#e6f6ee;color:#12694c;font-size:32px}.merchant-no{direction:ltr;display:inline-block;font:900 25px monospace;color:#12694c;background:#f0f7f4;padding:10px 16px;border-radius:11px}
        @media(max-width:720px){.hero{display:block}.hero .back{display:inline-block;margin-top:12px}.grid,.docs{grid-template-columns:1fr}.full{grid-column:auto}.steps{grid-template-columns:repeat(2,1fr)}.row{align-items:stretch;flex-direction:column}.actions{flex-direction:column}.submit{width:100%}.card{padding:18px}}
    </style>
</head>
<body>
<main class="page">
    <div class="brand">أميال <span>باي</span> <small>| الأعمال</small></div>
    <div class="hero">
        <div><h1>إنشاء حساب منشأة</h1><p>رحلة واحدة لكل القطاعات. القطاع الذي تختاره هنا هو مصدر الحقيقة الذي يحدد لوحة منشأتك وصلاحياتها.</p></div>
        <a class="back" href="{{ route('merchant.web.login') }}">لدي حساب بالفعل ←</a>
    </div>

    <div class="steps"><div class="step active">1 · المنشأة والمالك</div><div class="step active">2 · البريد الموثق</div><div class="step active">3 · العنوان والمستندات</div><div class="step active">4 · مراجعة الإدارة</div></div>

    <form id="register-form" enctype="multipart/form-data" novalidate>
        <section class="card">
            <h2>هوية المنشأة والمالك</h2>
            <div class="sub">لا نستنتج القطاع من اسم المتجر، ولا نغيّره تلقائياً إلى التجزئة.</div>
            <div class="grid">
                <label>اسم المنشأة<input name="store_name" required maxlength="120"></label>
                <label>قطاع المنشأة
                    <select name="business_type" required>
                        <option value="">اختر القطاع…</option>
                        @foreach($businessTypes as $type)
                            <option value="{{ $type['value'] }}">{{ $type['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label>الاسم<input name="f_name" required minlength="2" maxlength="60"></label>
                <label>اسم الأب<input name="father_name" required minlength="2" maxlength="60"></label>
                <label>اسم الجد<input name="grandfather_name" required minlength="2" maxlength="60"></label>
                <label>اللقب<input name="family_name" required minlength="2" maxlength="80"></label>
                <label>الجنس<select name="gender" required><option value="">اختر…</option><option value="male">ذكر</option><option value="female">أنثى</option></select></label>
                <label>رمز الدولة<input name="dial_country_code" value="+967" required maxlength="8" dir="ltr"></label>
                <label class="full">رقم الهاتف<input name="phone" required maxlength="20" inputmode="tel" dir="ltr"></label>
            </div>
        </section>

        <section class="card">
            <h2>البريد الإلكتروني الموثق</h2>
            <div class="sub">سيصبح البريد وسيلة موثوقة للدخول والاستعادة والتنبيهات. لا يُنشأ الحساب قبل إثبات ملكيته.</div>
            <div class="email-box">
                <div class="row">
                    <label class="grow">البريد الإلكتروني<input id="email" name="email" type="email" required maxlength="255" dir="ltr" autocomplete="email"></label>
                    <button type="button" class="secondary" id="send-email-code">إرسال رمز التحقق</button>
                </div>
                <div class="row" id="otp-row" hidden style="margin-top:12px">
                    <label class="grow">رمز البريد<input id="email-otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" dir="ltr" autocomplete="one-time-code"></label>
                    <button type="button" class="secondary" id="verify-email-code">تأكيد البريد</button>
                </div>
                <div id="email-status" class="status"></div>
            </div>
            <div class="grid" style="margin-top:14px">
                <label>رمز الدخول
                    <input name="password" type="password" required pattern="[0-9]{4}" inputmode="numeric" maxlength="4" autocomplete="new-password" dir="ltr">
                    <div class="hint">العقد الحالي للحسابات يستخدم 4 أرقام. تغيير كلمة المرور لاحقاً متاح بالبريد الموثق.</div>
                </label>
            </div>
        </section>

        <section class="card">
            <h2>العنوان والتحقق</h2>
            <div class="sub">نجمع ما تحتاجه الإدارة لاعتماد الحساب فعلياً؛ لا ننشئ حساباً يظل عالقاً بلا مستندات.</div>
            <div class="grid">
                <label>محافظة الميلاد
                    <select name="origin_governorate" required><option value="">اختر…</option>
                        @foreach($governorates as $g)<option value="{{ $g['code'] }}">{{ $g['name'] }}</option>@endforeach
                    </select>
                </label>
                <label>محافظة السكن الحالية
                    <select id="residence-governorate" name="residence_governorate" required><option value="">اختر…</option>
                        @foreach($governorates as $g)<option value="{{ $g['code'] }}">{{ $g['name'] }}</option>@endforeach
                    </select>
                </label>
                <label>المديرية<select id="residence-district" name="residence_district" required><option value="">اختر المحافظة أولاً…</option></select></label>
                <label>الحي / المنطقة<input name="residence_area" required maxlength="120"></label>
                <label class="full">العنوان التفصيلي<input name="address" required maxlength="500"></label>
            </div>
            <div class="docs" style="margin-top:16px">
                <label>وجه الهوية<input type="file" name="kyc_id_front" accept="image/jpeg,image/png,application/pdf" required><div class="hint">صورة واضحة أو PDF.</div></label>
                <label>ظهر الهوية<input type="file" name="kyc_id_back" accept="image/jpeg,image/png,application/pdf" required></label>
                <label>الصورة الشخصية<input type="file" name="kyc_selfie" accept="image/jpeg,image/png" required></label>
                <label>إثبات السكن<input type="file" name="kyc_address_proof" accept="image/jpeg,image/png,application/pdf"><div class="hint">يوصى برفعه الآن لتسريع المراجعة.</div></label>
            </div>
            <label class="agree"><input type="checkbox" id="declaration" required><span>أقر بأن البيانات تخصني وتخص المنشأة، وأن الاسم والقطاع والمستندات صحيحة ويمكن مطابقتها أثناء المراجعة.</span></label>
            <div id="form-status" class="status"></div>
            <div class="actions"><button id="submit-registration" class="primary submit" type="submit">إنشاء حساب المنشأة</button></div>
        </section>
    </form>

    <section class="card success" id="success-card">
        <div class="checkmark">✓</div>
        <h2>تم إنشاء حساب المنشأة</h2>
        <p class="sub">تم توثيق البريد وحفظ القطاع والمستندات. الحساب الآن قيد مراجعة الإدارة، ولن تُفتح لوحة التشغيل قبل الاعتماد.</p>
        <div>رقم التاجر</div><div class="merchant-no" id="merchant-number">—</div>
        <p class="sub" id="success-detail"></p>
        <a class="back" href="{{ route('merchant.web.login') }}">العودة إلى تسجيل الدخول</a>
    </section>
</main>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const districts = @json($districts);
    const form = document.getElementById('register-form');
    const email = document.getElementById('email');
    const otp = document.getElementById('email-otp');
    const otpRow = document.getElementById('otp-row');
    const emailStatus = document.getElementById('email-status');
    const formStatus = document.getElementById('form-status');
    const sendBtn = document.getElementById('send-email-code');
    const verifyBtn = document.getElementById('verify-email-code');
    const submitBtn = document.getElementById('submit-registration');
    const gov = document.getElementById('residence-governorate');
    const district = document.getElementById('residence-district');
    let challengeId = null, verificationToken = null, verifiedEmail = null;

    const status = (el, message, ok=false) => {
        el.textContent = message; el.className = 'status show ' + (ok ? 'ok' : 'err');
    };
    const clearStatus = el => { el.textContent=''; el.className='status'; };
    const errorMessage = body => {
        if (!body) return 'تعذر إتمام العملية.';
        if (typeof body.message === 'string' && body.message) return body.message;
        if (Array.isArray(body.errors) && body.errors.length) return body.errors[0].message || 'تحقق من البيانات.';
        if (body.errors && typeof body.errors === 'object') {
            const first = Object.values(body.errors).flat()[0];
            if (first) return String(first);
        }
        return 'تعذر إتمام العملية.';
    };
    const postJson = async (url, payload) => {
        const r = await fetch(url, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json'}, body:JSON.stringify(payload)});
        let b=null; try{b=await r.json()}catch(_){}
        if(!r.ok) throw new Error(errorMessage(b));
        return b;
    };

    const refillDistricts = () => {
        district.replaceChildren(new Option('اختر المديرية…',''));
        (districts[gov.value] || []).forEach(d => district.add(new Option(d.name_ar, d.name_ar)));
        district.disabled = !gov.value;
    };
    gov.addEventListener('change', refillDistricts);
    refillDistricts();

    email.addEventListener('input', () => {
        if (verifiedEmail !== email.value.trim().toLowerCase()) {
            verificationToken = null; verifiedEmail = null; challengeId = null;
            otpRow.hidden = true; clearStatus(emailStatus);
        }
    });

    sendBtn.addEventListener('click', async () => {
        const value=email.value.trim().toLowerCase();
        if(!email.checkValidity()){status(emailStatus,'اكتب بريداً إلكترونياً صحيحاً.');email.reportValidity();return}
        sendBtn.disabled=true; clearStatus(emailStatus);
        try{
            const b=await postJson('/api/v1/auth/email-otp/request',{email:value,purpose:'registration'});
            challengeId=b?.meta?.challenge_id || null;
            otpRow.hidden=false; otp.focus();
            status(emailStatus,'أرسلنا رمزاً من 6 أرقام إلى بريدك.',true);
        }catch(e){status(emailStatus,e.message)}
        finally{sendBtn.disabled=false}
    });

    verifyBtn.addEventListener('click', async () => {
        const value=email.value.trim().toLowerCase();
        if(!/^[0-9]{6}$/.test(otp.value.trim())){status(emailStatus,'أدخل رمز البريد المكوّن من 6 أرقام.');return}
        verifyBtn.disabled=true;
        try{
            const payload={email:value,purpose:'registration',otp:otp.value.trim()};
            if(challengeId) payload.challenge_id=challengeId;
            const b=await postJson('/api/v1/auth/email-otp/verify',payload);
            challengeId=b?.meta?.challenge_id || challengeId;
            verificationToken=b?.meta?.verification_token || null;
            if(!verificationToken) throw new Error('لم يصل إثبات التحقق من الخادم.');
            verifiedEmail=value;
            status(emailStatus,'تم توثيق البريد الإلكتروني ✓',true);
        }catch(e){verificationToken=null;verifiedEmail=null;status(emailStatus,e.message)}
        finally{verifyBtn.disabled=false}
    });

    form.addEventListener('submit', async e => {
        e.preventDefault(); clearStatus(formStatus);
        if(!form.reportValidity()) return;
        if(!verificationToken || verifiedEmail !== email.value.trim().toLowerCase()){
            status(formStatus,'يجب توثيق البريد الإلكتروني قبل إنشاء الحساب.');return;
        }
        submitBtn.disabled=true; submitBtn.textContent='جاري إنشاء الحساب…';
        try{
            const data=new FormData(form);
            data.set('account_type','merchant');
            data.set('email',verifiedEmail);
            data.set('email_challenge_id',challengeId);
            data.set('email_verification_token',verificationToken);
            data.set('l_name',data.get('family_name'));
            data.set('declaration_accepted','1');
            const r=await fetch('/api/v1/auth/register/email',{method:'POST',headers:{'Accept':'application/json'},body:data});
            let b=null;try{b=await r.json()}catch(_){}
            if(!r.ok) throw new Error(errorMessage(b));
            form.hidden=true;
            document.getElementById('success-card').classList.add('show');
            document.getElementById('merchant-number').textContent=b?.merchant_number || '—';
            document.getElementById('success-detail').textContent='احتفظ برقم التاجر. ستتمكن من دخول لوحة الأعمال بعد اعتماد ملف المنشأة.';
            window.scrollTo({top:0,behavior:'smooth'});
        }catch(err){
            status(formStatus,err.message);
            submitBtn.disabled=false;submitBtn.textContent='إنشاء حساب المنشأة';
        }
    });
})();
</script>
</body>
</html>
