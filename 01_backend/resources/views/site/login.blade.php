{{-- AMIAL-UNIFIED-WEB-LOGIN-001 — بابٌ واحد.

     **حقلٌ واحدٌ للمعرّف لا قائمةٌ منسدلة.**

     قائمةُ «أنا: موظّف / شركة / إدارة» تسأل المستعمل سؤالاً يعرف النظامُ
     جوابَه من حسابه. والصرّاف يقف أمامها كلّ صباح، ويخطئ فيها، فيُقال له
     «بيانات خاطئة» وبياناتُه صحيحة. والأسوأ أنّها لا تحرس شيئاً: ما يأتي
     من المتصفّح يمكن تغييره، فالصلاحيّة تُقرأ من الحساب لا من الاختيار.
     (القاعدة الثامنة: الهويّة تحدّد النطاق، لا القائمة المنسدلة.) --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول — أميال باي</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#053391">
    <link rel="stylesheet" href="{{ asset('assets/site/site.css') }}">
</head>
<body class="auth-page">

<div class="auth-card">
    <a class="logo" href="{{ route('site.home') }}"><span class="mark">🏦</span> أميال باي</a>
    <div class="sub">بوّابة الويب — لشركات الصرافة وإدارة المنصّة</div>

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('login.submit') }}" autocomplete="on">
        @csrf

        <div class="field">
            <label for="username">رمز الموظّف أو رقم الهاتف</label>
            <input class="input" id="username" name="username" type="text"
                   value="{{ old('username') }}" required autofocus
                   autocomplete="username" inputmode="text" dir="ltr"
                   placeholder="MKL-014">
            <div class="hint">الصرّاف يدخل برمزه، وصاحب الشركة والإدارة بالهاتف.</div>
        </div>

        <div class="field">
            <label for="password">كلمة السرّ</label>
            <input class="input" id="password" name="password" type="password"
                   required autocomplete="current-password">
        </div>

        @if ($needsCaptcha)
            {{-- تظهر بعد محاولاتٍ فاشلة فقط — لا في كلّ دخول. --}}
            <div class="field">
                <label for="captcha">اكتب ما في الصورة</label>
                <div class="captcha-row">
                    <img src="{{ route('login.captcha') }}" alt="رمز التحقّق" width="130" height="46">
                    <input class="input" id="captcha" name="captcha" type="text"
                           required autocomplete="off" dir="ltr" style="flex:1">
                </div>
                <div class="hint">ظهرت هذه الخطوة بعد محاولاتٍ فاشلة متتالية.</div>
            </div>
        @endif

        <label class="check">
            <input type="checkbox" name="remember" value="1"> أبقني داخلاً على هذا الجهاز
        </label>

        <button class="btn btn-primary btn-block btn-lg" type="submit">دخول</button>
    </form>

    {{-- **البوّابات تُعرَض ولا تُختار.**

         حقلٌ واحدٌ يقبل الجميع، والوجهةُ تُقرأ من الحساب. وهذه القائمة
         إخبارٌ لا اختيار: تقول للداخل أين سيصل قبل أن يصل، فلا يظنّ أنّه
         في المكان الخطأ. ولو كانت اختياراً لَحرَست لا شيء — ما يأتي من
         المتصفّح يمكن تغييره. (القاعدة الثامنة.) --}}
    <div class="portals">
        <div class="portals-t">إلى أين يقودك حسابك</div>
        <div class="portal"><span>🏪</span>
            <div><b>شركات الصرافة وموظّفوها</b>
                <small>{{ $agentHost ?? 'بوّابة الوكيل' }}</small></div>
        </div>
        <div class="portal"><span>🛡️</span>
            <div><b>موظّفو أميال باي</b>
                <small>{{ $adminHost ?? 'لوحة الإدارة' }} — والدورُ يُقرّر ما تراه</small></div>
        </div>
        <div class="portal muted"><span>📱</span>
            <div><b>العملاء والتجّار</b>
                <small>تطبيق أميال باي — لا على المتصفّح</small></div>
        </div>
    </div>

    <div class="auth-foot">
        <a href="{{ route('site.home') }}">العودة إلى الموقع</a>
    </div>
</div>

</body>
</html>
