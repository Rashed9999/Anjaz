{{-- AMIAL-AGENT-STAFF-001 — دخول بوّابة شركة الصرافة.

     كان هنا `asset('public/assets/admin/css/theme.min.css')` — ملفٌّ **لا
     وجود له في المستودع**، بل لا وجود لمجلّد `public/assets` كلّه. فكانت
     الصفحة تُحمَّل بلا Bootstrap فتظهر نصّاً خاماً بنقاطٍ سوداء.

     وهو عطلٌ لا تمسكه الاختبارات مهما كثرت: الصفحة تردّ 200 كاملةً وكلّ
     تأكيدٍ على محتواها يمرّ، وما ينقص يقع في المتصفّح وحده. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>بوّابة الوكيل — أميال باي</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/amial-tokens.css') }}" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(160deg, var(--amial-primary-dark) 0%, var(--amial-primary) 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1rem;
        }
        .login-card { width: 100%; max-width: 430px; border: 0; border-radius: 1.25rem;
                      box-shadow: 0 18px 50px rgba(0,0,0,.35); }
        .brand { font-size: var(--amial-text-2xl); font-weight: 700; color: var(--amial-primary-dark); }
    </style>
</head>
<body>

<div class="card login-card">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <div class="brand">🏦 أميال باي</div>
            <div class="text-muted small mt-1">بوّابة شركات الصرافة وفروعها</div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('agent.login.submit') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label fw-bold">رمز الموظّف أو هاتف الشركة</label>
                {{-- حقلٌ واحدٌ للاثنين: حقلان يجعلان الصرّاف يقف كلّ صباحٍ
                     أمام سؤالٍ لا يعنيه. --}}
                <input name="username" class="form-control form-control-lg" dir="ltr"
                       value="{{ old('username') }}" placeholder="MKL-014"
                       autofocus required data-testid="agent-username">
                <div class="form-text">الصرّاف يدخل برمزه، وصاحب الشركة بهاتفه.</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">كلمة السرّ</label>
                <input type="password" name="password" class="form-control form-control-lg"
                       dir="ltr" required data-testid="agent-password">
            </div>

            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" name="remember" id="rm" value="1">
                <label class="form-check-label small" for="rm">أبقني داخلاً على هذا الجهاز</label>
            </div>

            <button class="btn btn-primary btn-lg w-100" data-testid="agent-login">دخول</button>
        </form>

        @if (!request()->isSecure())
            {{-- تحذيرٌ صريحٌ لا صامت: موظّفٌ يكتب كلمة سرّه على اتّصالٍ غير
                 مشفَّر يستحقّ أن يعرف قبل أن يكتبها لا بعد. --}}
            <div class="alert alert-warning small mt-4 mb-0 py-2">
                ⚠️ الاتّصال غير مشفَّر (HTTP) — لا تستعمل هذه البوّابة على شبكةٍ عامّة
                قبل تفعيل HTTPS.
            </div>
        @endif

        <div class="text-center mt-3 small text-muted">
            هذه البوّابة لشركات الصرافة وموظّفيها. للعملاء: تطبيق أميال باي.
        </div>
    </div>
</div>

</body>
</html>
