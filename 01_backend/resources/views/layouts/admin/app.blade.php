<!doctype html>
{{-- AMIAL-ADMIN-UI-001: تخطيط لوحة الإدارة القياسي (كان مفقوداً — كل صفحات الأدمن
     تمدّده). واجهة نظيفة بـBootstrap 5. --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'الإدارة') — {{ Helpers::get_business_settings('business_name') ?? 'Amial Pay' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6fa; }
        .amial-sidebar { min-height:100vh; background:#0f2b46; color:#cfe0f2; }
        .amial-sidebar a { color:#cfe0f2; text-decoration:none; }
        .amial-sidebar a:hover, .amial-sidebar li.active > a { color:#fff; background:#17395c; border-radius:.5rem; }
        .amial-sidebar .nav-link { padding:.55rem .8rem; display:block; }
        .amial-brand { font-weight:700; font-size:1.15rem; color:#fff; }
        .nav-icon { display:none; } /* أيقونات Tio غير محمّلة — إخفاء آمن */
        .stat-card { border:0; border-radius:1rem; box-shadow:0 2px 10px rgba(0,0,0,.05); }
    </style>
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

            @yield('content')
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('script')
</body>
</html>
