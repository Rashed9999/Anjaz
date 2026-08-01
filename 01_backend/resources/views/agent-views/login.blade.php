<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بوّابة الوكيل — أميال باي</title>
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/theme.min.css') }}">
    <style>
        body { background:#0f1b2d; min-height:100vh; display:flex; align-items:center;
               font-family:'Tajawal',system-ui,sans-serif; }
        .card { max-width:420px; margin:auto; border-radius:16px; }
        .brand { font-size:26px; font-weight:800; color:#053391; }
    </style>
</head>
<body>
<div class="container">
    <div class="card p-4 bg-white shadow-lg">
        <div class="text-center mb-4">
            <div class="brand">💳 أميال باي</div>
            <div class="text-muted">بوّابة الوكيل والفروع</div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('agent.login.submit') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">رقم الهاتف</label>
                <input type="text" name="phone" class="form-control form-control-lg"
                       value="{{ old('phone') }}" required autofocus data-testid="agent-phone">
            </div>
            <div class="mb-3">
                <label class="form-label">كلمة المرور</label>
                <input type="password" name="password" class="form-control form-control-lg"
                       required data-testid="agent-password">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="remember" id="rm">
                <label class="form-check-label" for="rm">تذكّرني على هذا الجهاز</label>
            </div>
            <button class="btn btn-primary btn-lg w-100" data-testid="agent-login">دخول</button>
        </form>

        <div class="text-center mt-3 small text-muted">
            هذه البوّابة لشركات الصرافة وفروعها. للعملاء: تطبيق أميال باي.
        </div>
    </div>
</div>
</body>
</html>
