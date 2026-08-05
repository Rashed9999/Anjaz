{{-- AMIAL-SITE-001 — قالب الموقع العامّ.

     رأسٌ وذيلٌ وتنقّلٌ في موضعٍ واحد. وصفحةٌ تنسخ رأسَها تنسى تحديثه،
     فيصير للموقع رأسان يتباعدان.

     **ولا شيء هنا يمسّ قاعدة البيانات.** الموقع العامّ هو أوّل ما يراه
     الزائر، فيجب أن يعمل والقاعدة متوقّفة — وإلّا صار عطلٌ في قاعدةٍ
     داخليّة «الشركة كلّها لا تعمل» في نظر من يمرّ. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'أميال باي') — أميال باي</title>
    <meta name="description" content="@yield('desc', 'أميال باي — المحفظة الإلكترونيّة ونقاط البيع وشبكة الصرافة في اليمن.')">
    <meta name="theme-color" content="#053391">
    <link rel="stylesheet" href="{{ asset('assets/site/site.css') }}">
    @stack('head')
</head>
<body>

<header class="site-head">
    <div class="wrap">
        <a class="logo" href="{{ route('site.home') }}">
            <span class="mark">🏦</span> أميال باي
        </a>

        <button class="nav-toggle" type="button" aria-label="القائمة"
                aria-expanded="false" aria-controls="site-nav" data-nav-toggle>☰</button>

        <nav class="nav" id="site-nav">
            @php $current = request()->route()?->getName(); @endphp
            @foreach ([
                'site.personal' => 'للأفراد',
                'site.business' => 'للتجّار',
                'site.exchange' => 'لشركات الصرافة',
                'site.security' => 'الأمان',
                'site.faq'      => 'الأسئلة',
                'site.contact'  => 'تواصل معنا',
            ] as $name => $label)
                <a href="{{ route($name) }}"
                   @if($current === $name) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            {{-- بابان في الترويسة لا واحد.

                 كان فيها زرٌّ واحد يقود إلى `/login`، ومنه لا سبيلَ ظاهرٌ
                 إلى لوحة الإدارة. فمن أراد الإدارة وجب أن يكتب العنوان
                 بيده — وهو ما لا يفعله أحد، ولا يعرفه أصلاً. --}}
            <a class="nav-admin" href="{{ route('admin.auth.login') }}">🛡️ الإدارة</a>
            <a class="btn btn-primary" href="{{ route('login') }}">تسجيل الدخول</a>
        </nav>
    </div>
</header>

@yield('body')

<footer class="site-foot">
    <div class="wrap">
        <div class="cols">
            <div>
                <div class="brand">🏦 أميال باي</div>
                <div class="note">
                    محفظةٌ إلكترونيّة ونقاط بيعٍ وشبكةُ صرافةٍ في الجمهوريّة اليمنيّة.
                    خدماتُنا للأفراد والتجّار وشركات الصرافة وفروعها.
                </div>
            </div>

            <div>
                <h4>الخدمات</h4>
                <a href="{{ route('site.personal') }}">للأفراد</a>
                <a href="{{ route('site.business') }}">للتجّار ونقاط البيع</a>
                <a href="{{ route('site.exchange') }}">لشركات الصرافة</a>
            </div>

            <div>
                <h4>المنصّة</h4>
                <a href="{{ route('site.about') }}">من نحن</a>
                <a href="{{ route('site.security') }}">الأمان والامتثال</a>
                <a href="{{ route('site.faq') }}">الأسئلة الشائعة</a>
                <a href="{{ route('site.contact') }}">تواصل معنا</a>
            </div>

            <div>
                <h4>الدخول</h4>
                <a href="{{ route('login') }}">تسجيل الدخول</a>
                <a href="{{ route('agent.login') }}">بوّابة شركات الصرافة</a>
                <a href="{{ route('admin.auth.login') }}">لوحة الإدارة</a>
            </div>
        </div>

        <div class="bar">
            <div>© {{ date('Y') }} أميال باي — جميع الحقوق محفوظة</div>
            <div>
                <a href="{{ route('site.terms') }}" style="display:inline">الشروط</a> ·
                <a href="{{ route('site.privacy') }}" style="display:inline">الخصوصيّة</a>
            </div>
        </div>
    </div>
</footer>

{{-- سطرٌ واحد: فتح القائمة على الهاتف. ولا إطار من شبكةٍ خارجيّة لأجله. --}}
<script>
    (function () {
        var btn = document.querySelector('[data-nav-toggle]');
        var nav = document.getElementById('site-nav');
        if (!btn || !nav) { return; }
        btn.addEventListener('click', function () {
            var open = nav.classList.toggle('open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    })();
</script>
</body>
</html>
