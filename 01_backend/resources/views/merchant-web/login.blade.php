<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>دخول التاجر | أميال باي</title>
    <meta name="robots" content="noindex,nofollow">
    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:linear-gradient(138deg,#eef8f4,#f4f6fb);color:#132c29;font-family:Tahoma,"Segoe UI",sans-serif}
        .shell{width:min(93vw,460px);background:white;padding:34px;border:1px solid #dbe8e5;border-radius:24px;box-shadow:0 25px 90px #123c3020}
        .brand{font-weight:900;color:#116c50;letter-spacing:.5px;font-size:25px}.muted{color:#60736d;line-height:1.9}
        h1{font-size:25px;margin:32px 0 8px}label{display:block;margin:22px 0 8px;font-weight:700;font-size:14px}
        input{width:100%;height:49px;border:1px solid #cbdcd6;border-radius:11px;padding:0 13px;font:inherit;direction:ltr;text-align:right}
        input:focus{outline:2px solid #27987866;border-color:#217d60}
        button{margin-top:24px;width:100%;background:#12694c;border:0;color:white;font:700 16px Tahoma;padding:17px;border-radius:12px;cursor:pointer}
        button:hover{background:#0d523c}.error{background:#fff2ee;border:1px solid #f2b7a4;color:#a52c16;padding:12px;border-radius:10px;margin-top:17px}
        .foot{border-top:1px solid #e1e9e6;margin-top:25px;padding-top:20px;font-size:13px;color:#62716c}
        a{color:#156d52}.check{display:flex;gap:9px;align-items:center;margin-top:18px;color:#48655b}.check input{width:16px;height:16px}
    </style>
</head>
<body>
    <main class="shell">
        <div class="brand">أميال <span style="color:#daa62e">باي</span> <small style="font-size:12px;font-weight:400">| الأعمال</small></div>
        <h1>إدارة منشأتك تبدأ هنا</h1>
        <p class="muted">لوحة مستقلة لمالك المنشأة. المبيعات التي تُنجزها نقاط البيع تصب في محفظة منشأتك وتظهر في تقاريرك.</p>
        @if($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('merchant.web.login.submit') }}" autocomplete="on">
            @csrf
            <label for="identifier">رقم التاجر أو الهاتف أو البريد الإلكتروني</label>
            <input id="identifier" name="identifier" value="{{ old('identifier') }}" required autofocus maxlength="160" autocomplete="username">
            <label for="password">كلمة المرور</label>
            <input type="password" id="password" name="password" required maxlength="200" autocomplete="current-password">
            <label class="check"><input type="checkbox" name="remember" value="1"> تذكّر تسجيل الدخول على هذا الجهاز</label>
            <button type="submit">الدخول إلى لوحة التاجر ←</button>
        </form>
        <div class="foot">موظف نقطة البيع؟ استخدم تطبيق أميال باي بحسابك المستقل. <a href="{{ route('site.home') }}">العودة إلى الموقع</a></div>
    </main>
</body>
</html>
