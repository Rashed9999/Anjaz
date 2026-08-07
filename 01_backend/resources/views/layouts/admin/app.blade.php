<!doctype html>
{{-- AMIAL-ADMIN-UI-001: تخطيط لوحة الإدارة القياسي (كان مفقوداً — كل صفحات الأدمن
     تمدّده). واجهة نظيفة بـBootstrap 5. --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'الإدارة') — {{ Helpers::get_business_settings('business_name') ?? 'Amial Pay' }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    {{-- AMIAL-TOKENS-001 — الهويّة من مصدرٍ واحد.
         كان هنا أربعةَ عشرَ سطرَ CSS محشوّةً في القالب، فيها أزرقٌ
         (#0f2b46) لا علاقة له بأزرق العلامة في التطبيق (#053391)،
         وخلفيّةٌ (#f4f6fa) غير خلفيّته (#F2F3F7). علامةٌ واحدةٌ
         بهويّتين — والقيمُ تُنسخ ولا تُشتق. --}}
    <link href="{{ asset('assets/css/amial-tokens.css') }}" rel="stylesheet">
    @stack('css_or_js')
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- الشريط الجانبي -->
        <aside class="col-lg-2 col-md-3 amial-sidebar p-3" data-testid="admin-sidebar">
            <div class="amial-brand mb-4">💳 {{ Helpers::get_business_settings('business_name') ?? 'Amial Pay' }}</div>
            <ul class="nav nav-pills nav-vertical card-navbar-nav flex-column gap-1">
                <li class="nav-item {{ Request::is('admin') ? 'active' : '' }}">
                    <a class="nav-link" href="{{ route('admin.dashboard') }}" data-testid="nav-dashboard">
                        <i class="tio-home nav-icon"></i>{{ 'لوحة التحكم' }}
                    </a>
                </li>
                {{-- AMIAL-ADMIN-MENU-002: حُذف من هنا رابطان كانا يكرّران
                     وجهتين موجودتين في القائمة أدناه:

                       • «مركز العمليات» → admin/support-center، وهو نفسه
                         «مركز الدعم» في مجموعة «الخدمات والعملاء».
                       • «وضع الصيانة» → admin/maintenance، وهو نفسه في
                         مجموعة «الإعدادات والتشغيل».

                     ووجهةٌ واحدة باسمين ليست إزعاجاً بصريّاً فحسب: تجعل
                     المستخدم يظنّهما شاشتين فيجرّب الاثنتين، ثمّ يشكّ في
                     أنّه فوّت شيئاً حين يجد الشاشة نفسها. --}}
                @includeIf('admin-views.amial.partials._sidebar')
                <li class="nav-item mt-3">
                    <a class="nav-link" href="{{ route('admin.auth.logout') }}" data-testid="nav-logout">
                        <i class="tio-logout nav-icon"></i>{{ 'تسجيل الخروج' }}
                    </a>
                </li>
            </ul>
        </aside>

        <!-- المحتوى -->
        <main class="col-lg-10 col-md-9 p-4">
            <header class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="m-0" data-testid="page-title">@yield('title', 'الإدارة')</h4>
                <span class="text-muted small">{{ auth('user')->user()?->f_name ?? 'الإدارة' }}</span>
            </header>

            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

            @include('partials._clock_guard')

            @yield('content')
        </main>
    </div>
</div>
<script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
{{-- AMIAL-PILOT-IDEM-002 — يُحمَّل **قبل** شيفرة الصفحات عمداً:
     هي تُنادي fetch، فلا بدّ أن يكون اللفُّ قائماً قبل أوّل نداء.
     ووسيطٌ بلا مفتاحٍ من العميل حمايتُه صفر — يُولّد مفتاحاً عشوائيّاً
     لكلّ طلب، فتصير كلُّ ضغطةٍ عمليّةً جديدة. --}}
<script src="{{ asset('assets/js/amial-idempotency.js') }}"></script>
@stack('script')
</body>
</html>
