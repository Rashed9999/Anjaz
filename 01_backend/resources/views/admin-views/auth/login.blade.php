<!doctype html>
{{-- AMIAL-ADMIN-UI-002 — بوابة الإدارة: واجهةٌ واحدة لا تغيّر عقد الدخول. --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#021F5C">
    <title>دخول الإدارة — {{ Helpers::get_business_settings('business_name') ?? 'أميال باي' }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    {{-- كان القالب يستعمل متغيرات العلامة بلا تحميل هذا الملف، فتسقط الخلفية
         إلى أبيض وتبدو صفحة الإنتاج كقالب Bootstrap افتراضي. --}}
    <link href="{{ asset('assets/css/amial-tokens.css') }}" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { min-height:100vh; margin:0; background:var(--amial-background); color:var(--amial-text); font-family:var(--amial-font); }
        .admin-auth { min-height:100vh; display:grid; grid-template-columns:minmax(0,1.08fr) minmax(390px,.92fr); }
        .admin-brand { position:relative; overflow:hidden; padding:clamp(2rem,6vw,6rem); display:flex; flex-direction:column; justify-content:space-between; color:#fff; background:linear-gradient(135deg,var(--amial-primary-dark),var(--amial-primary)); }
        .admin-brand::before, .admin-brand::after { content:""; position:absolute; border:1px solid rgba(255,255,255,.14); border-radius:50%; pointer-events:none; }
        .admin-brand::before { width:33rem; height:33rem; top:-16rem; left:-12rem; }
        .admin-brand::after { width:23rem; height:23rem; bottom:-12rem; right:-9rem; background:rgba(255,255,255,.04); }
        .brand-content, .brand-footer { position:relative; z-index:1; max-width:34rem; }
        .brand-logo { display:inline-flex; align-items:center; gap:.8rem; color:#fff; text-decoration:none; width:max-content; }
        .brand-logo img { width:48px; height:48px; object-fit:contain; border-radius:14px; background:#fff; padding:5px; }
        .brand-logo strong { font-size:1.1rem; } .brand-logo small { display:block; opacity:.72; letter-spacing:.08em; font-size:.68rem; }
        .eyebrow { display:inline-flex; align-items:center; width:max-content; margin:clamp(3rem,10vh,9rem) 0 1rem; padding:.4rem .7rem; border:1px solid rgba(255,255,255,.25); border-radius:999px; font-size:.8rem; font-weight:600; background:rgba(255,255,255,.08); }
        .admin-brand h1 { font-size:clamp(2rem,3.8vw,3.6rem); line-height:1.3; margin:0 0 1rem; font-weight:700; }
        .admin-brand p { max-width:30rem; margin:0; color:rgba(255,255,255,.78); line-height:1.9; }
        .brand-points { display:grid; gap:.75rem; margin-top:2rem; font-size:.92rem; color:rgba(255,255,255,.88); }
        .brand-points span { display:flex; align-items:center; gap:.55rem; }.brand-points i { width:1.35rem; height:1.35rem; display:grid; place-items:center; border-radius:50%; background:rgba(254,202,30,.22); color:var(--amial-yellow); font-style:normal; }
        .brand-footer { color:rgba(255,255,255,.58); font-size:.78rem; }
        .admin-form-side { min-width:0; display:grid; place-items:center; padding:clamp(1.25rem,5vw,4rem); }
        .admin-form-wrap { width:min(100%,29rem); }
        .back-link { display:inline-flex; align-items:center; gap:.4rem; margin-bottom:2rem; color:var(--amial-text-secondary); text-decoration:none; font-size:.88rem; }.back-link:hover { color:var(--amial-primary); }
        .admin-form-wrap h2 { margin:0; font-size:1.65rem; font-weight:700; }.admin-form-wrap > p { color:var(--amial-text-secondary); line-height:1.75; margin:.55rem 0 1.75rem; }
        .auth-card { padding:clamp(1.25rem,3vw,2rem); border:1px solid var(--amial-border); border-radius:1.25rem; background:var(--amial-surface); box-shadow:0 18px 45px rgba(5,51,145,.10); }
        .form-label { margin-bottom:.45rem; font-weight:600; font-size:.9rem; }.form-control { min-height:3rem; border-color:var(--amial-border); border-radius:.7rem; }.form-control:focus { border-color:var(--amial-primary-light); box-shadow:0 0 0 .22rem rgba(29,79,184,.15); }
        .captcha-row { display:flex; align-items:center; gap:.7rem; }.captcha-image { min-width:112px; height:46px; object-fit:contain; border:1px solid var(--amial-border); border-radius:.65rem; background:#fff; }.captcha-refresh { width:46px; height:46px; flex:0 0 46px; border:1px solid var(--amial-border); border-radius:.65rem; color:var(--amial-primary); background:#fff; }.captcha-refresh:hover { border-color:var(--amial-primary); background:#f5f8ff; }
        .remember-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; color:var(--amial-text-secondary); font-size:.86rem; }.form-check-input:checked { background-color:var(--amial-primary); border-color:var(--amial-primary); }
        .login-submit { min-height:3.15rem; border:0; border-radius:.75rem; font-weight:700; background:var(--amial-primary); box-shadow:0 8px 18px rgba(5,51,145,.22); }.login-submit:hover,.login-submit:focus { background:var(--amial-primary-dark); }.login-submit[aria-busy="true"] { opacity:.8; cursor:wait; }
        .security-note { display:flex; gap:.65rem; margin:1.2rem 0 0; padding:.8rem .9rem; border-radius:.75rem; background:#f4f7ff; color:var(--amial-text-secondary); font-size:.78rem; line-height:1.65; }.security-note b { color:var(--amial-primary); }
        .auth-error { border:0; border-right:3px solid var(--amial-danger); border-radius:.7rem; font-size:.88rem; }
        @media (max-width:900px) { .admin-auth { grid-template-columns:1fr; }.admin-brand { min-height:auto; padding:1.5rem; }.eyebrow,.brand-points,.brand-footer,.admin-brand p { display:none; }.admin-brand h1 { font-size:1.3rem; margin:1rem 0 0; }.admin-form-side { align-items:start; padding:1.5rem 1rem 2.5rem; }.back-link { margin-bottom:1.5rem; } }
    </style>
</head>
<body>
@php($captchaUrl = route('admin.auth.default-captcha', ['tmp' => '__AMIAL_CAPTCHA__']))
<main class="admin-auth">
    <section class="admin-brand" aria-label="هوية أميال باي">
        <div class="brand-content">
            <a class="brand-logo" href="{{ url('/') }}" aria-label="العودة إلى موقع أميال باي">
                <img src="{{ asset('branding/logo.png') }}" alt="شعار أميال باي">
                <span><strong>{{ Helpers::get_business_settings('business_name') ?? 'أميال باي' }}</strong><small>AMIAL PAY</small></span>
            </a>
            <div class="eyebrow">بوابة داخلية محمية</div>
            <h1>إدارة المنصة تبدأ بدخولٍ آمن.</h1>
            <p>هذه البوابة مخصصة لموظفي أميال باي المخولين. أدخل بياناتك، وسيطلب النظام رمز التحقق الإضافي عند تفعيله على حسابك.</p>
            <div class="brand-points" aria-label="خصائص الحماية"><span><i>✓</i>CAPTCHA يمنع المحاولات الآلية</span><span><i>✓</i>التحقق الثنائي عند تفعيله</span><span><i>✓</i>جلسة منفصلة عن بوابات العملاء والوكلاء</span></div>
        </div>
        <div class="brand-footer">أميال باي · تشغيل إداري موثوق</div>
    </section>

    <section class="admin-form-side" aria-labelledby="admin-login-title">
        <div class="admin-form-wrap">
            <a class="back-link" href="{{ url('/') }}">← العودة إلى الموقع</a>
            <h2 id="admin-login-title">تسجيل دخول الإدارة</h2>
            <p>أدخل رقم الهاتف وكلمة المرور ورمز التحقق الظاهر أدناه.</p>
            <div class="auth-card" data-testid="login-card">
                @if($errors->any())
                    <div class="alert alert-danger auth-error py-2 mb-3" role="alert" data-testid="login-error">{{ $errors->first() }}</div>
                @endif
                <form method="POST" action="{{ route('admin.auth.login') }}" data-testid="login-form" id="admin-login-form">
                    @csrf
                    <div class="mb-3"><label class="form-label" for="login-phone">رقم الهاتف</label><input id="login-phone" type="tel" name="phone" class="form-control" data-testid="login-phone" value="{{ old('phone') }}" inputmode="tel" autocomplete="username" placeholder="9677xxxxxxxx" required autofocus></div>
                    <div class="mb-3"><label class="form-label" for="login-password">كلمة المرور</label><input id="login-password" type="password" name="password" class="form-control" data-testid="login-password" autocomplete="current-password" required></div>
                    <div class="mb-3"><label class="form-label" for="login-captcha">رمز التحقق</label><div class="captcha-row mb-2"><img id="admin-captcha-image" class="captcha-image" src="{{ str_replace('__AMIAL_CAPTCHA__', (string) time(), $captchaUrl) }}" alt="رمز CAPTCHA" data-testid="captcha-img"><button class="captcha-refresh" id="admin-captcha-refresh" type="button" aria-label="تحديث رمز التحقق" title="تحديث رمز التحقق">↻</button></div><input id="login-captcha" type="text" name="default_captcha_value" class="form-control" data-testid="login-captcha" autocomplete="off" required></div>
                    <div class="remember-row mb-4"><div class="form-check m-0"><input class="form-check-input" type="checkbox" name="remember" id="remember" value="1"><label class="form-check-label" for="remember">تذكرني على هذا الجهاز</label></div></div>
                    <button type="submit" class="btn btn-primary w-100 login-submit" data-testid="login-submit" id="admin-login-submit"><span>دخول آمن</span><span class="spinner-border spinner-border-sm ms-2 d-none" aria-hidden="true"></span></button>
                </form>
                <div class="security-note"><span aria-hidden="true">🛡️</span><span><b>تنبيه أمني:</b> لا تستخدم هذه البوابة إلا من جهاز موثوق. لا يمكن لكلمة المرور وحدها تجاوز التحقق الثنائي عند تفعيله.</span></div>
            </div>
        </div>
    </section>
</main>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
    (() => {
        const image = document.getElementById('admin-captcha-image');
        const refresh = document.getElementById('admin-captcha-refresh');
        const form = document.getElementById('admin-login-form');
        const submit = document.getElementById('admin-login-submit');
        const captchaBase = @json($captchaUrl);
        refresh?.addEventListener('click', () => { image.src = captchaBase.replace('__AMIAL_CAPTCHA__', String(Date.now())); });
        form?.addEventListener('submit', () => { submit.setAttribute('aria-busy', 'true'); submit.disabled = true; submit.querySelector('.spinner-border')?.classList.remove('d-none'); });
    })();
</script>
</body>
</html>
