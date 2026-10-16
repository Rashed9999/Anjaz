<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $storeName }} | لوحة أميال للأعمال</title>
    @include('merchant-web.partials.dashboard-styles')

</head>
<body>
<div class="shell">
    <header class="mobile-bar">
        <button type="button" class="nav-toggle" id="menu-toggle" aria-controls="merchant-side" aria-expanded="false" aria-label="فتح القائمة">☰</button>
        <span class="mobile-brand">أميال <strong>باي</strong> <small>الأعمال</small></span>
        <span class="mobile-store">{{ $storeName }}</span>
    </header>
    <button type="button" class="nav-backdrop" id="nav-backdrop" aria-label="إغلاق القائمة" hidden></button>
    <aside class="side" id="merchant-side" aria-label="التنقل داخل لوحة المنشأة">
        <div class="brand">أميال <span>باي</span> <small style="font-size:12px">الأعمال</small></div>
        <div class="store"><strong>{{ $storeName }}</strong><small>{{ $businessType }} · {{ $plan }}</small></div>
        <nav id="portal-nav" aria-label="أقسام منشأتك"></nav>
        <form class="logout" method="post" action="{{ route('merchant.web.logout') }}">@csrf
            <button type="submit">تسجيل الخروج</button>
        </form>
    </aside>
    <main>
        <header class="top">
            <div>
                <div class="eyebrow">AMIAL BUSINESS · مركز إدارة المنشأة</div>
                <h1 id="page-title">{{ $portalNavigation[0]['label'] ?? 'لوحة المنشأة' }}</h1>
                <p class="muted">{{ $storeName }} · أرقام التشغيل والمالية من مصادرها الفعلية داخل المنشأة</p>
            </div>
            <div class="top-tools">
                <span class="live-pill">بيانات مباشرة</span>
                <span class="badge">{{ $businessType }} · {{ $plan }}</span>
            </div>
        </header>
        <div id="content" aria-live="polite"><div class="kpi-grid"><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></div></div>
    </main>
</div>
<div id="message" role="status"></div>
@include('merchant-web.partials.dashboard-script')
</body>
</html>
