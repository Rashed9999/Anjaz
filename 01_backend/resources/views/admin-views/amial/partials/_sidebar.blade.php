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
    <small class="nav-subtitle text-uppercase fw-bold" title="{{ 'قسم أميال باي' }}">
        أميال باي
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
    {{-- AMIAL-TXN-ADMIN-001: كشف المعاملات الكامل بفلاتر + تصدير --}}
    <a class="nav-link" href="{{route('admin.transaction.index')}}" title="كشف المعاملات">
        <span class="text-truncate">📊 كشف المعاملات (فلاتر + تصدير)</span>
    </a>
    {{-- AMIAL-CONTENT-001: إدارة المحتوى (بانرات + إشعارات) --}}
    <a class="nav-link" href="{{route('admin.banner.index')}}" title="البانرات">
        <span class="text-truncate">🖼️ بانرات الرئيسية</span>
    </a>
    <a class="nav-link" href="{{route('admin.notification.add-new')}}" title="الإشعارات">
        <span class="text-truncate">🔔 إشعارات الدفع</span>
    </a>
    {{-- AMIAL-FCM-002: الصفحة كانت بلا رابط في القائمة إطلاقاً --}}
    <a class="nav-link" href="{{route('admin.business-settings.fcm-index')}}" title="إعداد Firebase">
        <span class="text-truncate">🔥 إعداد Firebase</span>
    </a>
    {{-- AMIAL-ZONE-PANEL-001: سياسة المناطق كانت بلا واجهة إطلاقاً --}}
    <a class="nav-link" href="{{route('admin.amial.hub.zones.index')}}" title="لوحة المناطق">
        <span class="text-truncate">🗺️ لوحة المناطق (النطاق والمخالفات)</span>
    </a>
    <a class="nav-link" href="{{route('admin.faq.index')}}" title="الأسئلة الشائعة">
        <span class="text-truncate">❓ الأسئلة الشائعة</span>
    </a>
    {{-- AMIAL-EMONEY-001: رصيد المنصّة (إنشاء/شحن) --}}
    <a class="nav-link" href="{{route('admin.emoney.index')}}" title="رصيد المنصّة">
        <span class="text-truncate">🏦 رصيد المنصّة (إنشاء)</span>
    </a>
    {{-- AMIAL-EXPENSE-001 / AMIAL-LANG-ADMIN-001: أنظمة كانت بلا واجهة --}}
    <a class="nav-link" href="{{route('admin.expense.index')}}" title="المصاريف">
        <span class="text-truncate">💸 مصاريف المنصّة</span>
    </a>
    <a class="nav-link" href="{{route('admin.business-settings.language.index')}}" title="اللغات">
        <span class="text-truncate">🌐 إدارة اللغات</span>
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

{{--
    AMIAL-ADMIN-REACH-001 — أربع لوحاتٍ كان منطقُها مبنيّاً ولا رابط يصل إليها.

    وهذا نمطٌ تكرّر: يُبنى المنطق وتُسجَّل نقاط النهاية ويُختبر كلّ شيء، ثمّ
    لا يُفتح من متصفّح قطّ. والنتيجة ضابطٌ قائمٌ في الكود غائبٌ عن العمل —
    وهو أسوأ من غيابه، لأنّ من يقرأ الوثيقة يحسبه يعمل.
--}}
<li class="nav-item">
    <small class="nav-subtitle text-uppercase fw-bold">الامتثال والرقابة</small>
</li>

{{-- AMIAL-KYC-PANEL-001 — طابور مراجعة الهوية --}}
@if (auth('user')->check() && auth('user')->user()->hasPlatformPermission('platform.customers.freeze'))
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/kyc*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.kyc.page')}}" title="مراجعة الهوية">
        <i class="tio-verified nav-icon"></i>
        <span class="text-truncate">🪪 مراجعة مستندات الهوية</span>
    </a>
</li>
@endif

{{-- AMIAL-AML-PANEL-001 — اثنتا عشرة نقطة نهاية بلا صفحة واحدة --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/aml*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.aml.page')}}" title="مكافحة غسل الأموال">
        <i class="tio-shield-outlined nav-icon"></i>
        <span class="text-truncate">🛡️ مكافحة غسل الأموال (المعلَّق والقواعد)</span>
    </a>
</li>

{{-- AMIAL-LEDGER-CENTER-001 — الدفتر أُصلح كلّه ولم تكن تقرؤه شاشة --}}
@if (auth('user')->check() && auth('user')->user()->hasPlatformPermission('platform.audit.view'))
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/ledger*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.ledger.page')}}" title="مركز الدفتر">
        <i class="tio-book nav-icon"></i>
        <span class="text-truncate">📚 مركز الدفتر (ميزان المراجعة)</span>
    </a>
</li>
@endif

{{-- AMIAL-SETTLEMENT-PANEL-001 — حيث تعيش الموافقة المزدوجة --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/partner-settlements*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.partner-settlements.page')}}" title="تسويات الشركاء">
        <i class="tio-receipt-outlined nav-icon"></i>
        <span class="text-truncate">🤝 تسويات الشركاء (الموافقة المزدوجة)</span>
    </a>
</li>

{{-- Executive Dashboard (AMIAL-EXEC-DASHBOARD-001) --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/executive*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.executive.index')}}"
       title="{{'لوحة القيادة التنفيذية'}}">
        <i class="tio-chart-bar-1 nav-icon"></i>
        <span class="text-truncate">{{'لوحة القيادة التنفيذية'}}</span>
    </a>
</li>

{{-- Zone Management --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/zones*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.zones.index')}}"
       title="{{'إدارة المناطق'}}">
        <i class="tio-globe nav-icon"></i>
        <span class="text-truncate">{{'إدارة المناطق'}}</span>
    </a>
</li>

{{-- Fee & Profit Control (AMIAL-FEE-ENGINE-001) --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/fees*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.fees.index')}}"
       title="{{'التحكّم بالرسوم والأرباح'}}">
        <i class="tio-percent nav-icon"></i>
        <span class="text-truncate">{{'التحكّم بالرسوم والأرباح'}}</span>
    </a>
</li>

{{-- Legal Terms --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/legal*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.legal.index')}}"
       title="{{'الشروط القانونية'}}">
        <i class="tio-document-text nav-icon"></i>
        <span class="text-truncate">{{'الشروط القانونية'}}</span>
    </a>
</li>

{{-- Account Recovery --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/recovery*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.recovery.index')}}"
       title="{{'استعادة الحسابات'}}">
        <i class="tio-shield-outlined nav-icon"></i>
        <span class="text-truncate">{{'استعادة الحسابات'}}</span>
        @if(isset($pending_recovery_count) && $pending_recovery_count > 0)
            <span class="badge badge-soft-warning rounded-pill ms-auto">{{ $pending_recovery_count }}</span>
        @endif
    </a>
</li>

{{-- Security Events --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/security-events*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.security-events.index')}}"
       title="{{'أحداث الأمان'}}">
        <i class="tio-warning nav-icon"></i>
        <span class="text-truncate">{{'أحداث الأمان'}}</span>
    </a>
</li>

{{-- AMIAL-OPS-CONSOLE-001 — حالة التشغيل (فريق الصيانة) --}}
{{-- يظهر لمن يملك صلاحية العرض وحده: رابطٌ يُفتح فيُردّ 403 يُربك أكثر ممّا يفيد. --}}
@if (auth('user')->check() && auth('user')->user()->hasPlatformPermission('platform.ops.view'))
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/ops*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.ops.index')}}" title="حالة التشغيل">
        <i class="tio-settings-outlined nav-icon"></i>
        <span class="text-truncate">🩺 حالة التشغيل (الطوابير والمستندات)</span>
    </a>
</li>
@endif

{{-- AMIAL-OPERATOR-RBAC-003 — أدوار الموظّفين (مدير المنصّة وحده) --}}
@if (auth('user')->check() && auth('user')->user()->hasPlatformPermission('platform.settings.update'))
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/ops/roles*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.ops.roles.index')}}" title="أدوار الموظّفين">
        <i class="tio-user-switch nav-icon"></i>
        <span class="text-truncate">👥 أدوار الموظّفين (الدعم/الصيانة/الإشراف)</span>
    </a>
</li>
@endif

{{-- AMIAL-SUPERVISION-001 — لوحة الإشراف --}}
@if (auth('user')->check() && auth('user')->user()->hasPlatformPermission('platform.audit.view'))
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/supervision*')?'active':''}}">
    <a class="nav-link" href="{{route('admin.amial.supervision.index')}}" title="لوحة الإشراف">
        <i class="tio-visible-outlined nav-icon"></i>
        <span class="text-truncate">👁️ لوحة الإشراف (الفريق والقرارات)</span>
    </a>
</li>
@endif

{{-- Audit Decisions --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/audit*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.audit.index')}}"
       title="{{'سجلّ تدقيق النظام'}}">
        <i class="tio-search nav-icon"></i>
        <span class="text-truncate">{{'سجلّ تدقيق النظام'}}</span>
    </a>
</li>

{{-- Security Sentinel (AMIAL-SENTINEL-001) --}}
<li class="navbar-vertical-aside-has-menu {{Request::is('admin/amial/sentinel*')?'active':''}}">
    <a class="nav-link"
       href="{{route('admin.amial.sentinel.index')}}"
       title="{{'حارس الأمان'}}">
        <i class="tio-security nav-icon"></i>
        <span class="text-truncate">{{'حارس الأمان'}}</span>
    </a>
</li>
