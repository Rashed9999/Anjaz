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
    <style>
        .admin-mobile-toggle,.admin-sidebar-close,.amial-sidebar-backdrop { display:none; }
        @media (max-width: 767.98px) {
            .admin-mobile-toggle { display:inline-flex; align-items:center; justify-content:center; width:42px; height:42px; border:0; border-radius:12px; background:var(--amial-primary); color:var(--amial-surface); font-size:20px; }
            .amial-sidebar { position:fixed !important; z-index:1060; top:0; right:0; bottom:0; width:min(86vw,330px) !important; max-width:none !important; height:100dvh; overflow-y:auto; transform:translateX(106%); transition:transform .22s ease; box-shadow:-18px 0 46px rgba(10,28,68,.22); }
            body.amial-sidebar-open { overflow:hidden; }
            body.amial-sidebar-open .amial-sidebar { transform:translateX(0); }
            .amial-sidebar-backdrop { position:fixed; inset:0; z-index:1055; background:rgba(8,19,43,.5); opacity:0; pointer-events:none; transition:opacity .22s ease; }
            body.amial-sidebar-open .amial-sidebar-backdrop { display:block; opacity:1; pointer-events:auto; }
            .admin-sidebar-close { display:inline-flex; width:34px; height:34px; align-items:center; justify-content:center; border:0; border-radius:10px; background:rgba(255,255,255,.13); color:var(--amial-surface); font-size:24px; line-height:1; }
            .admin-main { width:100%; padding:18px 14px 30px !important; }
            .admin-main > header { margin-bottom:18px !important; }
        }
    </style>
    @stack('css_or_js')
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- الشريط الجانبي -->
        <aside class="col-lg-2 col-md-3 amial-sidebar p-3" data-testid="admin-sidebar" aria-label="التنقل الرئيسي">
            <div class="amial-brand mb-4 d-flex align-items-center justify-content-between">
                <span>💳 {{ Helpers::get_business_settings('business_name') ?? 'Amial Pay' }}</span>
                <button type="button" class="admin-sidebar-close" data-admin-sidebar-close aria-label="إغلاق القائمة">×</button>
            </div>
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
        <div class="amial-sidebar-backdrop" data-admin-sidebar-close></div>

        <!-- المحتوى -->
        <main class="col-lg-10 col-md-9 p-4 admin-main">
            <header class="d-flex justify-content-between align-items-center mb-4">
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="admin-mobile-toggle" data-admin-sidebar-open aria-label="فتح القائمة">☰</button>

                    {{-- ══════════════════════════════════════════════════
                         AMIAL-ADMIN-BACK-001 — **زرُّ الرجوع، وكان مفقوداً.**

                         قاله صاحب المشروع: «مساحة العمل لا يوجد أزرار رجوع
                         للخلف».

                         وقِيس فكان محقّاً: مساحةُ العمل تقود إلى **نيّفٍ
                         وخمسين شاشة**، وليس في أيٍّ منها بابُ عودة. والقائمةُ
                         الجانبيّةُ تحمل خمسةَ بنودٍ فقط، فمن دخل «سجلّ
                         التدقيق» لا يجد طريقاً إلى «الامتثال والمخاطر» إلّا
                         بزرّ المتصفّح — وهو غيرُ موجودٍ في تطبيق الويب
                         المثبَّت على الشاشة الرئيسة.

                         **ويوضع في المخطَّط لا في كلّ شاشة**: نثرُه في
                         الشاشات يُنتج ما أنتجه غيرُه في هذا المشروع —
                         شاشةٌ فيها بابٌ وأختُها بلا باب، ولا يُعرف السببُ
                         إلّا بالتجربة.

                         **ولا يظهر في مساحة العمل نفسِها ولا في اللوحة** —
                         زرُّ رجوعٍ إلى الصفحة التي أنت فيها عطلٌ صغير.
                         ══════════════════════════════════════════════════ --}}
                    @php($amialBackHidden = request()->routeIs(
                        'admin.amial.workspace.index', 'admin.dashboard', 'admin.amial.dashboard'))
                    @unless($amialBackHidden)
                        <a href="{{ route('admin.amial.workspace.index') }}"
                           class="btn btn-sm btn-outline-secondary py-0 px-2"
                           data-testid="admin-back"
                           data-amial-back
                           title="رجوع">← رجوع</a>
                    @endunless

                    <h4 class="m-0" data-testid="page-title">@yield('title', 'الإدارة')</h4>
                </div>
                <div class="d-flex align-items-center gap-3">
                    {{-- AMIAL-I18N-001 — مبدّلُ اللغة: عربيّ افتراضاً، وإنجليزيّ بضغطة. --}}
                    @php($amialLocale = session('local', 'ar'))
                    <form method="POST" action="{{ route('admin.amial.locale') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $amialLocale === 'ar' ? 'en' : 'ar' }}">
                        <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                data-testid="locale-toggle"
                                title="{{ $amialLocale === 'ar' ? 'Switch to English' : 'التبديل إلى العربيّة' }}">
                            {{ $amialLocale === 'ar' ? 'EN' : 'ع' }}
                        </button>
                    </form>
                    <span class="text-muted small">{{ auth('user')->user()?->f_name ?? 'الإدارة' }}</span>
                </div>
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
@include('partials._app_dialogs')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
    (() => {
        const body = document.body;
        const open = document.querySelector('[data-admin-sidebar-open]');
        const closes = document.querySelectorAll('[data-admin-sidebar-close]');
        const close = () => body.classList.remove('amial-sidebar-open');
        open?.addEventListener('click', () => body.classList.add('amial-sidebar-open'));
        closes.forEach((button) => button.addEventListener('click', close));
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
    })();
</script>
@stack('script')
</body>
</html>
