<!doctype html>
{{-- AMIAL-ADMIN-UI-001: صفحة دخول لوحة الإدارة (كانت مفقودة). --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ translate('Admin Login') }} — {{ Helpers::get_business_settings('business_name') ?? 'Amial Pay' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { background:linear-gradient(135deg,#0f2b46,#17395c); min-height:100vh; display:flex; align-items:center; }
        .login-card { border:0; border-radius:1.25rem; box-shadow:0 10px 40px rgba(0,0,0,.25); }
        .brand { font-weight:800; color:#0f2b46; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card login-card p-4" data-testid="login-card">
                <div class="text-center mb-3">
                    <div class="brand fs-3">💳 {{ Helpers::get_business_settings('business_name') ?? 'Amial Pay' }}</div>
                    <div class="text-muted small">{{ translate('Admin Panel') }}</div>
                </div>

                @if($errors->any())
                    <div class="alert alert-danger py-2" data-testid="login-error">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('admin.auth.login') }}" data-testid="login-form">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">{{ translate('Phone') }}</label>
                        <input type="text" name="phone" class="form-control" data-testid="login-phone"
                               value="{{ old('phone') }}" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ translate('Password') }}</label>
                        <input type="password" name="password" class="form-control" data-testid="login-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ translate('Captcha') }}</label>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <img src="{{ route('admin.auth.default-captcha', ['tmp' => time()]) }}"
                                 alt="captcha" data-testid="captcha-img" style="height:40px;border-radius:.4rem;">
                        </div>
                        <input type="text" name="default_captcha_value" class="form-control"
                               data-testid="login-captcha" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                        <label class="form-check-label" for="remember">{{ translate('Remember me') }}</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" data-testid="login-submit">
                        {{ translate('Login') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
