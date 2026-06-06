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
