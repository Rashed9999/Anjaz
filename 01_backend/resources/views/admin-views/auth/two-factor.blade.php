<!doctype html>
{{--
    AMIAL-2FA-DOOR-001 — بوّابةُ الرمز الثاني.

    **الشاشة التي لم تكن موجودة.** والمحرّكُ مكتملٌ منذ v1.8، والأعمدةُ
    الخمسةُ في الجدول — ولا شاشةَ تُفعّلها ولا سطرَ يفحصها عند الدخول.

    والجلسةُ **لم تُفتح بعد**: المعرّفُ معلّقٌ في الجلسة بلا أيّ صلاحيّة.
    فمن أغلق هذه الصفحة لم يدخل.
--}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ translate('Two-Factor Authentication') }} — {{ Helpers::get_business_settings('business_name') ?? 'Amial Pay' }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <style>
        body { background:linear-gradient(135deg,#0f2b46,#17395c); min-height:100vh; display:flex; align-items:center; }
        .tf-card { border:0; border-radius:1.25rem; box-shadow:0 10px 40px rgba(0,0,0,.25); }
        .brand { font-weight:800; color:#0f2b46; }
        .code-input { letter-spacing:.6rem; text-align:center; font-size:1.5rem; font-weight:700; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card tf-card p-4" data-testid="two-factor-card">
                <div class="text-center mb-3">
                    <div class="brand fs-3">🔐 {{ translate('Two-Factor Authentication') }}</div>
                    <div class="text-muted small">أدخل الرمز من تطبيق المصادقة</div>
                </div>

                @if($errors->any())
                    <div class="alert alert-danger py-2" data-testid="two-factor-error">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.auth.two-factor.verify') }}"
                      data-testid="two-factor-form">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label small">الرمز</label>
                        <input type="text" name="code" class="form-control code-input"
                               inputmode="numeric" autocomplete="one-time-code"
                               maxlength="20" autofocus required
                               data-testid="two-factor-code" placeholder="······">
                        <div class="form-text">
                            ستّةُ أرقامٍ من التطبيق — أو أحدُ رموز الاسترداد إن فقدتَ هاتفك.
                        </div>
                    </div>

                    <button class="btn btn-primary w-100 mb-2" data-testid="two-factor-submit">
                        دخول
                    </button>
                </form>

                <a href="{{ route('admin.auth.two-factor.cancel') }}"
                   class="btn btn-link w-100 text-muted small"
                   data-testid="two-factor-cancel">الرجوع إلى تسجيل الدخول</a>

                <div class="alert alert-light border small mt-2 mb-0">
                    <strong>لم تدخل بعد.</strong> كلمةُ المرور وحدها لا تفتح اللوحة —
                    وإغلاقُ هذه الصفحة يُنهي المحاولة.
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
