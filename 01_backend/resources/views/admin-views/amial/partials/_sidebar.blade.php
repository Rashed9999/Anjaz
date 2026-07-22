{{--
    AMIAL-ADMIN-001 (v0.8)

    قائمة جانبية لـ Amial Pay features.

    تعليمات الدمج:
    افتح resources/views/layouts/admin/partials/_sidebar.blade.php
    وأضف هذا الـ <include> داخل <ul class="nav nav-pills nav-vertical card-navbar-nav"> الموجود:

        @include('admin-views.amial.partials._sidebar')

    الموضع المُوصى به: بعد قسم "الإدارة المالية" وقبل "user management".
--}}

<li class="nav-item">
    <small class="nav-subtitle text-uppercase fw-bold" title="{{ translate('Amial Pay Section') }}">
        Amial Pay
    </small>
</li>

{{-- AMIAL-ADMIN-HUB-001 — اللوحات المركزية الأربع --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/customers*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.customers')}}" title="مركز العملاء">
        <i class="tio-group nav-icon"></i>
        <span class="text-truncate">👥 مركز العملاء</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/agents*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.agents')}}" title="مركز الوكلاء">
        <i class="tio-briefcase nav-icon"></i>
        <span class="text-truncate">🤝 مركز الوكلاء</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/merchants*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.merchants')}}" title="مركز التجّار">
        <i class="tio-shop nav-icon"></i>
        <span class="text-truncate">🏪 مركز التجّار</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/finance*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.finance')}}" title="المركز المالي">
        <i class="tio-money nav-icon"></i>
        <span class="text-truncate">💰 المركز المالي (بثّ حيّ)</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/verification*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.verification')}}" title="لوحة التحقق">
        <i class="tio-verified nav-icon"></i>
        <span class="text-truncate">🪪 لوحة التحقق (الحسابات الجديدة)</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/subscriptions*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.subscriptions')}}" title="لوحة الاشتراكات">
        <i class="tio-diamond nav-icon"></i>
        <span class="text-truncate">💎 لوحة الاشتراكات</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/disputes*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.disputes')}}" title="لوحة النزاعات">
        <i class="tio-gavel nav-icon"></i>
        <span class="text-truncate">⚖️ لوحة النزاعات (دفع آمن)</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/settlements*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.settlements')}}" title="لوحة التسويات">
        <i class="tio-receipt nav-icon"></i>
        <span class="text-truncate">🧾 لوحة التسويات (الوكلاء)</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/staff*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.hub.staff')}}" title="لوحة الموظفين">
        <i class="tio-user-add nav-icon"></i>
        <span class="text-truncate">👔 لوحة الموظفين (نقاط البيع)</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/hub/settings*')?'active':''}}">
    {{-- AMIAL-SURFACE-001: أنظمة كانت بلا لوحة --}}
    <a class="nav-link" href="{{route('admin.amial.charity.page')}}" title="لوحة التبرعات">
        <span class="text-truncate">🎗️ لوحة التبرعات (الجمعيات)</span>
    </a>
    <a class="nav-link" href="{{route('admin.amial.surface.bill-providers')}}" title="مزوّدو الفواتير">
        <span class="text-truncate">⚡ مزوّدو الفواتير</span>
    </a>
    <a class="nav-link" href="{{route('admin.amial.surface.funds')}}" title="صناديق العائلة">
        <span class="text-truncate">👨‍👩‍👧 صناديق العائلة</span>
    </a>
    <a class="nav-link" href="{{route('admin.amial.surface.payment-requests')}}" title="طلبات الأموال">
        <span class="text-truncate">📨 طلبات الأموال</span>
    </a>
    <a class="nav-link" href="{{route('admin.amial.surface.rbac')}}" title="الصلاحيات">
        <span class="text-truncate">🛡️ الصلاحيات (RBAC)</span>
    </a>
    <a class="nav-link" href="{{route('admin.support-center.index')}}" title="مركز الدعم">
        <span class="text-truncate">🎧 مركز الدعم (بحث شامل)</span>
    </a>
    {{-- AMIAL-BIZ-SETUP-001: إعدادات الأعمال بتبويبات (6cash-style) --}}
    <a class="nav-link" href="{{route('admin.business-settings.business-setup')}}" title="إعدادات الأعمال">
        <span class="text-truncate">🏢 إعدادات الأعمال (عام/رسوم)</span>
    </a>
    <a class="nav-link" href="{{route('admin.amial.hub.settings')}}" title="لوحة الإعدادات">
        <i class="tio-settings nav-icon"></i>
        <span class="text-truncate">⚙️ لوحة الإعدادات (بضغطة زر)</span>
    </a>
</li>
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/maintenance*')?'active':''}}">
    <a class="nav-link" href="{{ url('admin/maintenance') }}" title="وضع الصيانة">
        <i class="tio-settings nav-icon"></i>
        <span class="text-truncate">🛠️ وضع الصيانة (إيقاف/تشغيل الخدمات)</span>
    </a>
</li>

{{-- Executive Dashboard (AMIAL-EXEC-DASHBOARD-001) --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/executive*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.executive.index')}}"
       title="{{translate('Executive Dashboard')}}">
        <i class="tio-chart-bar-1 nav-icon"></i>
        <span class="text-truncate">{{translate('Executive Dashboard')}}</span>
    </a>
</li>

{{-- Zone Management --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/zones*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.zones.index')}}"
       title="{{translate('Zone Management')}}">
        <i class="tio-globe nav-icon"></i>
        <span class="text-truncate">{{translate('Zone Management')}}</span>
    </a>
</li>

{{-- Fee & Profit Control (AMIAL-FEE-ENGINE-001) --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/fees*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.fees.index')}}"
       title="{{translate('Fee & Profit Control')}}">
        <i class="tio-percent nav-icon"></i>
        <span class="text-truncate">{{translate('Fee & Profit Control')}}</span>
    </a>
</li>

{{-- Legal Terms --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/legal*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.legal.index')}}"
       title="{{translate('Legal Terms')}}">
        <i class="tio-document-text nav-icon"></i>
        <span class="text-truncate">{{translate('Legal Terms')}}</span>
    </a>
</li>

{{-- Account Recovery --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/recovery*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.recovery.index')}}"
       title="{{translate('Account Recovery')}}">
        <i class="tio-shield-outlined nav-icon"></i>
        <span class="text-truncate">{{translate('Account Recovery')}}</span>
        @if(isset($pending_recovery_count) && $pending_recovery_count > 0)
            <span class="badge badge-soft-warning rounded-pill ms-auto">{{ $pending_recovery_count }}</span>
        @endif
    </a>
</li>

{{-- Security Events --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/security-events*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.security-events.index')}}"
       title="{{translate('Security Events')}}">
        <i class="tio-warning nav-icon"></i>
        <span class="text-truncate">{{translate('Security Events')}}</span>
    </a>
</li>

{{-- Audit Decisions --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/audit*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.audit.index')}}"
       title="{{translate('System Audit Log')}}">
        <i class="tio-search nav-icon"></i>
        <span class="text-truncate">{{translate('System Audit Log')}}</span>
    </a>
</li>

{{-- Security Sentinel (AMIAL-SENTINEL-001) --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/sentinel*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.sentinel.index')}}"
       title="{{translate('Security Sentinel')}}">
        <i class="tio-security nav-icon"></i>
        <span class="text-truncate">{{translate('Security Sentinel')}}</span>
    </a>
</li>
