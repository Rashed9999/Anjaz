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
    <div class="sub">بوّابة الويب — بابٌ واحدٌ يفتح على لوحتك</div>

    {{-- **من له جلسةٌ يرى النموذج، لا يُحوَّل عنه.**

         كانت الصفحة تُحوّل من له جلسةٍ إلى لوحته. فمن جرّب الدخول برمز
         صرّافٍ مرّةً بقيت جلستُه، وصارت كلُّ ضغطةٍ على «تسجيل الدخول» في
         الموقع ترميه إلى بوّابة الوكيل — ولا يرى النموذج إطلاقاً، ولا
         سبيل له إلى لوحةٍ أخرى.

         فيُقال له من هو، ويُترك له البابان. --}}
    @if (!empty($current))
        <div class="alert alert-info" style="text-align:start">
            <div style="margin-bottom:10px">
                أنت داخلٌ الآن باسم <strong>{{ $current['name'] }}</strong>
                في {{ $current['where'] }}.
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a class="btn btn-primary" style="padding:8px 16px;font-size:14px"
                   href="{{ $current['go'] }}">تابِع إلى لوحتك</a>
                <a class="btn btn-ghost" style="padding:8px 16px;font-size:14px"
                   href="{{ $current['out'] }}">خروج ودخولٌ بحسابٍ آخر</a>
            </div>
        </div>
    @endif

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
                   placeholder="MKL-014  أو  9677xxxxxxxx">
            {{-- كان المثال رمزَ صرّافٍ وحده، والسطرُ يبدأ بـ«الصرّاف» —
                 فقرأها الأدمن بوّابةَ وكيلٍ وظنّ أنّه في المكان الخطأ. --}}
            <div class="hint">
                موظّف الصرافة برمزه · صاحب الشركة والإدارة برقم الهاتف.
            </div>
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

    {{-- **كانت هذه القائمة معروضةً ولا تعمل — ثلاثةُ سطورٍ تشبه القائمة
         ولا يُفتح منها شيء.**

         وكتبتُ حينها أنّها «إخبارٌ لا اختيار» لأنّ الصلاحيّة تُقرأ من
         الحساب لا من قائمةٍ منسدلة. والمبدأ صحيح — **وطبّقتُه في غير
         موضعه**: قائمةٌ تقول «أنا أدمن» لا تمنح شيئاً، لكنّ **رابطاً إلى
         صفحة دخولٍ عامّة تنقّلٌ لا تصريح**. ومنعُه ليس حمايةً بل عرقلة.

         فلم يجد صاحب المشروع سبيلاً إلى لوحة الإدارة من الموقع إطلاقاً،
         وأخبرني أربع مرّات.

         (مهارة `amial-interactive-ui`: «No fake UI. Everything visible
         must function.» — وهي التي تكشف هذا الصنف بعينه.) --}}
    <div class="portals">
        <div class="portals-t">أو ادخل من بوّابتك مباشرةً</div>

        <a class="portal" href="{{ route('agent.login') }}">
            <span>🏪</span>
            <div><b>شركات الصرافة وموظّفوها</b>
                <small>{{ $agentHost ?? 'بوّابة الوكيل' }}</small></div>
            <em>←</em>
        </a>

        <a class="portal" href="{{ route('admin.auth.login') }}">
            <span>🛡️</span>
            <div><b>موظّفو أميال باي</b>
                <small>{{ $adminHost ?? 'لوحة الإدارة' }} — والدورُ يُقرّر ما تراه</small></div>
            <em>←</em>
        </a>

        {{-- والثالث بلا رابطٍ **بسببٍ مكتوب**: لا صفحةَ ويب له أصلاً.
             («Never leave disabled UI without reason».) --}}
        <div class="portal muted"><span>📱</span>
            <div><b>العملاء والتجّار</b>
                <small>تطبيق أميال باي — لا صفحةَ ويب لهم</small></div>
        </div>
    </div>

    <div class="auth-foot">
        <a href="{{ route('site.home') }}">العودة إلى الموقع</a>
    </div>
</div>

</body>
</html>
