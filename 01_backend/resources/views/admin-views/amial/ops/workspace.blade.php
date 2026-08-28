@extends('layouts.admin.app')
@section('title', 'مساحة عمل المنصّة')
@section('content')
@php
    $u = auth('user')->user();
    // لا تتحول مساحة العمل إلى 500 إن تعذر فحص استحقاق واحد؛ الافتراض منع
    // لا سماح، ويُسجّل الخطأ في سجل Laravel ليبقى التشخيص ممكناً.
    $can = function (?string $permission) use ($u): bool {
        if ($permission === null) return true;
        try {
            return $u && method_exists($u, 'hasPlatformPermission')
                && $u->hasPlatformPermission($permission);
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    };
    $tabs = [
        ['id'=>'overview','title'=>'نظرة عامة','icon'=>'📊','items'=>[
            ['لوحة القيادة التنفيذية', route('admin.amial.executive.index'), 'platform.audit.view'],
        ]],
        ['id'=>'customers','title'=>'دعم العملاء والحسابات','icon'=>'👥','items'=>[
            ['مركز العملاء', route('admin.amial.customer.page'), 'platform.customers.view'], ['إنشاء العملاء', route('admin.amial.hub.customers'), 'platform.customers.view'], ['مركز الدعم', route('admin.support-center.index'), 'platform.tickets.view'], ['لوحة التحقق: الحسابات الجديدة', route('admin.amial.hub.verification'), 'platform.approvals.decide'], ['استعادة الحسابات', route('admin.amial.recovery.index'), 'platform.approvals.decide'], ['بوابات OTP', route('admin.amial.otp.page'), 'platform.settings.update'],
        ]],
        ['id'=>'merchants','title'=>'التجّار','icon'=>'🏪','items'=>[
            ['مركز التجّار', route('admin.amial.hub.merchants'), 'platform.merchants.compliance'], ['الاشتراكات والباقات', route('admin.amial.hub.subscriptions'), 'platform.settings.manage'], ['القدرات والاستحقاقات', route('admin.amial.entitlements.page'), 'platform.settings.manage'], ['فواتير التجار', route('admin.amial.invoices.page'), 'platform.money.view'], ['كتالوج المنتجات', route('admin.amial.catalog.page'), 'platform.settings.update'], ['موظفو التجّار ونقاط البيع', route('admin.amial.hub.staff'), 'platform.merchants.compliance'], ['رقابة محطات الوقود', route('admin.amial.fuel.page'), 'platform.audit.view'], ['رقابة التجزئة', route('admin.amial.retail.page'), 'platform.audit.view'],
        ]],
        ['id'=>'agents','title'=>'الوكلاء','icon'=>'🤝','items'=>[
            ['مركز الوكلاء', route('admin.amial.hub.agents'), 'platform.customers.view'], ['تسويات الوكلاء', route('admin.amial.hub.settlements'), 'platform.money.view'], ['بوابة الوكيل', route('agent.login'), null, '_blank'],
        ]],
        ['id'=>'finance','title'=>'المالية والدفتر','icon'=>'📚','items'=>[
            ['المركز المالي', route('admin.amial.hub.finance'), 'platform.money.view'], ['مركز الدفتر', route('admin.amial.ledger.page'), 'platform.audit.view'], ['كشف المعاملات', route('admin.transaction.index'), 'platform.transactions.view'], ['تسويات الشركاء', route('admin.amial.partner-settlements.page'), 'platform.money.view'], ['رصيد المنصّة', route('admin.emoney.index'), 'platform.money.view'], ['مصاريف المنصّة', route('admin.expense.index'), 'platform.money.view'], ['الرسوم والأرباح', route('admin.amial.fees.index'), 'platform.fees.view'], ['طلبات السحب', route('admin.withdraw.index'), 'platform.audit.view'],
        ]],
        ['id'=>'compliance','title'=>'الامتثال والمخاطر','icon'=>'🛡️','items'=>[
            ['مراجعة الهوية', route('admin.amial.kyc.page'), 'platform.customers.kyc.view'], ['طلبات تحديث بيانات العملاء', route('admin.amial.kyc.changes.page'), 'platform.customers.freeze'], ['ملفات فتح الحسابات', route('admin.amial.registration-dossiers.page'), 'platform.registrations.view'], ['مكافحة غسل الأموال', route('admin.amial.aml.page'), 'platform.audit.view'], ['سجل التدقيق', route('admin.amial.audit.index'), 'platform.audit.view'], ['الإشراف والأمن', route('admin.amial.supervision.index'), 'platform.audit.view'], ['أحداث الأمان', route('admin.amial.security-events.index'), 'platform.audit.view'], ['حارس الأمان', route('admin.amial.sentinel.index'), 'platform.audit.view'], ['صحة النظام', route('admin.amial.system.health'), 'platform.audit.view'], ['ساهر — رادار النظام', route('admin.amial.saher.index'), 'saher.view'],
        ]],
        ['id'=>'services','title'=>'خدمات المنصّة','icon'=>'🧩','items'=>[
            ['النزاعات والدفع الآمن', route('admin.amial.hub.disputes'), 'platform.transactions.view'], ['الجمعيات والتبرعات', route('admin.amial.charity.page'), 'platform.transactions.view'], ['مزوّدو الفواتير', route('admin.amial.surface.bill-providers'), 'platform.settings.update'], ['صناديق العائلة', route('admin.amial.surface.funds'), 'platform.transactions.view'], ['طلبات الأموال', route('admin.amial.surface.payment-requests'), 'platform.transactions.view'],
        ]],
        ['id'=>'content','title'=>'المحتوى والتواصل','icon'=>'📣','items'=>[
            ['البانرات', route('admin.banner.index'), 'platform.settings.update'], ['إشعارات الدفع', route('admin.notification.add-new'), 'platform.settings.update'], ['الأسئلة الشائعة', route('admin.faq.index'), 'platform.settings.update'], ['اللغات', route('admin.business-settings.language.index'), 'platform.settings.update'],
        ]],
        ['id'=>'operations','title'=>'التشغيل والإعدادات','icon'=>'⚙️','items'=>[
            ['إعدادات الأعمال', route('admin.business-settings.business-setup'), 'platform.settings.update'], ['مفاتيح التشغيل', route('admin.amial.hub.settings'), 'platform.settings.update'], ['حدود بوت واتساب', route('admin.amial.whatsapp.limits.page'), 'platform.settings.update'], ['نطاق التشغيل', route('admin.amial.hub.zones.index'), 'platform.zones.view'], ['إعداد Firebase', route('admin.business-settings.fcm-index'), 'platform.settings.update'], ['الشروط القانونية', route('admin.amial.legal.index'), 'platform.ops.view'], ['حالة التشغيل', route('admin.amial.ops.index'), 'platform.ops.view'],
        ]],
        ['id'=>'staff','title'=>'الموظفون والصلاحيات','icon'=>'🔐','items'=>[
            ['موظفو المنصة وتبويباتهم', route('admin.amial.ops.roles.index'), 'platform.staff.view'], ['مصفوفة RBAC', route('admin.amial.surface.rbac'), 'platform.settings.update'], ['المصادقة الثنائية لحسابي', route('admin.amial.2fa.page'), null],
        ]],
    ];
    $tabs = array_values(array_filter($tabs, fn($tab) => collect($tab['items'])->contains(fn($item) => $can($item[2]))));
@endphp
<div class="content container-fluid">
    <div class="d-flex align-items-center gap-3 mb-4"><div><h2 class="page-header-title mb-1">مساحة عمل المنصّة</h2><p class="text-muted mb-0">كل الخدمات في تبويبات واضحة. ما لا يملكه الموظف لا يظهر ولا يفتح.</p></div></div>
    @if($tabs === [])<div class="alert alert-warning">لا توجد تبويبات ممنوحة لهذا الحساب. راجع مدير المنصة.</div>@else
    <ul class="nav nav-pills gap-2 mb-4 flex-wrap" role="tablist">@foreach($tabs as $index => $tab)<li class="nav-item"><button class="nav-link {{ $index === 0 ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#workspace-{{ $tab['id'] }}" type="button">{{ $tab['icon'] }} {{ $tab['title'] }}</button></li>@endforeach</ul>
    <div class="tab-content">@foreach($tabs as $index => $tab)<div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="workspace-{{ $tab['id'] }}"><div class="row g-3">@foreach($tab['items'] as $item)
                @continue(! $can($item[2]))
                @php $blank = ($item[3] ?? null) === '_blank'; @endphp
                <div class="col-md-6 col-xl-4">
                    {{-- AMIAL-WORKSPACE-BLADE-001 — **الشرطُ يُحسَب قبل الوسم.**

                         كان مكتوباً داخل الوسم:
                           @if(($item[3] ?? null) === '_blank') …

                         ومحلّلُ Blade يقف عند أوّل قوسٍ متوازن، فيقرأ
                         `(($item[3] ?? null)` شرطاً ويترك `=== '_blank')`
                         نصّاً خارجه — فينكسر القالبُ بـ«unexpected endif».

                         **والنتيجةُ 500 على مساحة العمل كلِّها**، وهي
                         الصفحةُ التي صارت تحمل كلَّ روابط اللوحة بعد نقلها
                         من الشريط الجانبيّ. أي أنّ لوحةَ الإدارة كلَّها
                         بلا مدخل. --}}
                    <a class="card h-100 text-decoration-none" href="{{ $item[1] }}"
                       @if($blank) target="_blank" rel="noopener" @endif>
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <strong>{{ $item[0] }}</strong><span>←</span>
                        </div>
                    </a>
                </div>
            @endforeach</div></div>@endforeach</div>
    @endif
</div>
@endsection
