<!doctype html>
{{-- AMIAL-ADMIN-UI-002 — الخطوة الثانية من مدخل الإدارة نفسه. --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#021F5C">
    <title>التحقق الثنائي — {{ Helpers::get_business_settings('business_name') ?? 'أميال باي' }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/amial-tokens.css') }}" rel="stylesheet">
    <style>
        * { box-sizing:border-box; } body { min-height:100vh; margin:0; display:grid; place-items:center; padding:1.25rem; color:var(--amial-text); font-family:var(--amial-font); background:radial-gradient(circle at 12% 12%,rgba(254,202,30,.16),transparent 26rem),linear-gradient(135deg,var(--amial-primary-dark),var(--amial-primary)); }
        .two-factor-shell { width:min(100%,31rem); }.brand { display:flex; align-items:center; justify-content:center; gap:.7rem; margin-bottom:1.4rem; color:#fff; text-decoration:none; }.brand img { width:42px; height:42px; padding:4px; object-fit:contain; border-radius:12px; background:#fff; }.brand strong { display:block; }.brand small { display:block; opacity:.72; font-size:.7rem; letter-spacing:.08em; }
        .tf-card { padding:clamp(1.35rem,5vw,2.3rem); border:0; border-radius:1.25rem; background:#fff; box-shadow:0 22px 55px rgba(0,0,0,.24); }.icon { width:3.2rem; height:3.2rem; display:grid; place-items:center; margin:0 auto 1rem; border-radius:1rem; color:var(--amial-primary); background:#eef4ff; font-size:1.55rem; }.tf-card h1 { margin:0; text-align:center; font-size:1.45rem; font-weight:700; }.intro { margin:.55rem auto 1.6rem; max-width:24rem; text-align:center; color:var(--amial-text-secondary); line-height:1.75; font-size:.9rem; }.form-label { font-weight:600; font-size:.9rem; }.code-input { min-height:3.45rem; letter-spacing:.48rem; text-align:center; direction:ltr; font-size:1.45rem; font-weight:700; border-color:var(--amial-border); border-radius:.75rem; }.code-input:focus { border-color:var(--amial-primary-light); box-shadow:0 0 0 .22rem rgba(29,79,184,.15); }.tf-submit { min-height:3.1rem; border:0; border-radius:.75rem; font-weight:700; background:var(--amial-primary); }.tf-submit:hover { background:var(--amial-primary-dark); }.tf-submit[aria-busy="true"] { opacity:.8; cursor:wait; }.help { margin-top:1rem; padding:.8rem .9rem; border-radius:.7rem; color:var(--amial-text-secondary); background:#f4f7ff; font-size:.8rem; line-height:1.65; }.back { display:block; margin-top:1.15rem; color:var(--amial-text-secondary); font-size:.86rem; text-align:center; text-decoration:none; }.back:hover { color:var(--amial-primary); }.auth-error { border:0; border-right:3px solid var(--amial-danger); border-radius:.7rem; font-size:.88rem; }
    </style>
</head>
<body>
<main class="two-factor-shell" aria-labelledby="two-factor-title">
    <a class="brand" href="{{ url('/') }}" aria-label="العودة إلى موقع أميال باي"><img src="{{ asset('branding/logo.png') }}" alt="شعار أميال باي"><span><strong>{{ Helpers::get_business_settings('business_name') ?? 'أميال باي' }}</strong><small>AMIAL PAY</small></span></a>
    <section class="tf-card" data-testid="two-factor-card">
        <div class="icon" aria-hidden="true">🔐</div>
        <h1 id="two-factor-title">تحقق إضافي لحماية الإدارة</h1>
        <p class="intro">أدخل رمز تطبيق المصادقة أو أحد رموز الاسترداد. لم تُفتح جلسة الإدارة بعد.</p>
        @if($errors->any())
            <div class="alert alert-danger auth-error py-2 mb-3" role="alert" data-testid="two-factor-error">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('admin.auth.two-factor.verify') }}" data-testid="two-factor-form" id="two-factor-form">
            @csrf
            <div class="mb-3"><label class="form-label" for="two-factor-code">رمز التحقق</label><input id="two-factor-code" type="text" name="code" class="form-control code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="20" autofocus required data-testid="two-factor-code" placeholder="······"></div>
            <button class="btn btn-primary w-100 tf-submit" data-testid="two-factor-submit" id="two-factor-submit"><span>تحقق ومتابعة</span><span class="spinner-border spinner-border-sm ms-2 d-none" aria-hidden="true"></span></button>
        </form>
        <div class="help"><strong>مهم:</strong> إغلاق هذه الصفحة أو الرجوع منها ينهي محاولة الدخول؛ كلمة المرور وحدها لا تمنح صلاحية لوحة الإدارة عند تفعيل التحقق الثنائي.</div>
        <a href="{{ route('admin.auth.two-factor.cancel') }}" class="back" data-testid="two-factor-cancel">الرجوع إلى تسجيل الدخول</a>
    </section>
</main>
<script>document.getElementById('two-factor-form')?.addEventListener('submit', () => { const button = document.getElementById('two-factor-submit'); button.setAttribute('aria-busy', 'true'); button.disabled = true; button.querySelector('.spinner-border')?.classList.remove('d-none'); });</script>
</body>
</html>
