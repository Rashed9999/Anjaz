@extends('layouts.admin.app')

@section('title', 'مركز التقارير والذكاء التنفيذي')

@php
    $totalReports = max(0, (int) ($summary['total'] ?? 0));
    $readyReports = max(0, (int) ($summary['ready'] ?? 0));
    $partialReports = max(0, (int) ($summary['partial'] ?? 0));
    $missingReports = max(0, (int) ($summary['missing'] ?? 0));
    $readinessPct = $totalReports > 0 ? round(($readyReports / $totalReports) * 100) : 0;
@endphp

@push('css_or_js')
<link rel="stylesheet" href="{{ asset('assets/admin/css/amial-reporting-intelligence.css') }}">
@endpush

@section('content')
<div id="ari-root" class="container-fluid px-0 ari" data-base-url="{{ rtrim(route('admin.amial.reporting-center.index'), '/') }}">
    <div class="ari-shell">
        <section class="ari-hero mb-4">
            <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-4 position-relative" style="z-index:2">
                <div>
                    <div class="ari-eyebrow">AMIAL PAY · EXECUTIVE INTELLIGENCE</div>
                    <h2>مركز التقارير والذكاء التنفيذي</h2>
                    <p>لوحة قرار موحدة تربط المال والسيولة والتشغيل والهوية والرقابة بمصادر الحقيقة الفعلية. المقارنات تُحسب على فترة سابقة مساوية، ولا تُجمع العملات المختلفة في رقم مالي واحد.</p>
                </div>
                <div class="ari-hero-meta">
                    <span class="ari-chip"><span class="ari-live"></span> بيانات حية من الخادم</span>
                    <span class="ari-chip">جاهز {{ $readyReports }} / {{ $totalReports }}</span>
                    @if($canExport)<span class="ari-chip">التصدير مصرح</span>@endif
                    <a class="ari-chip" href="{{ route('admin.amial.reporting-center.index', ['dashboard' => 2]) }}">Dashboard V2</a>
                    <a class="ari-chip" href="{{ route('admin.amial.reporting-center.index', ['legacy' => 1]) }}">العرض التشغيلي</a>
                </div>
            </div>
        </section>

        <section class="ari-filter mb-4">
            <div class="ari-card">
                <div class="ari-card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-3 col-md-6"><div class="ari-label">من تاريخ</div><input id="ari-from" class="form-control" type="date" value="{{ now()->startOfMonth()->toDateString() }}"></div>
                        <div class="col-lg-3 col-md-6"><div class="ari-label">إلى / كما في</div><input id="ari-to" class="form-control" type="date" value="{{ now()->toDateString() }}"></div>
                        <div class="col-lg-3 col-md-6"><div class="ari-label">الفترة السابقة المقارنة</div><div id="ari-previous-period" class="form-control d-flex align-items-center bg-white text-muted">تُحسب تلقائياً</div></div>
                        <div class="col-lg-3 col-md-6"><div class="ari-label">حالة البيانات</div><div class="d-flex gap-2"><div id="ari-refresh-state" class="form-control d-flex align-items-center bg-white text-muted flex-grow-1">جاهز</div><button id="ari-refresh" type="button" class="ari-btn ari-btn-primary">تحديث</button></div></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="ari-kpis mb-4">
            <div class="ari-kpi"><div class="ari-kpi-label">جاهزية منظومة التقارير</div><div class="ari-kpi-value">{{ $readinessPct }}%</div><div class="ari-progress"><span style="width:{{ min(100, $readinessPct) }}%"></span></div><div class="ari-kpi-note mt-2">{{ $readyReports }} جاهز · {{ $partialReports }} جزئي · {{ $missingReports }} ناقص</div></div>
            <div class="ari-kpi"><div class="ari-kpi-label">المعاملات الأصلية في الفترة</div><div id="ari-kpi-transactions" class="ari-kpi-value">—</div><div id="ari-kpi-transactions-note" class="ari-kpi-note">تحميل من دفتر الحركة</div></div>
            <div class="ari-kpi"><div class="ari-kpi-label">تغطية السيولة حسب العملات</div><div id="ari-kpi-liquidity" class="ari-kpi-value">—</div><div id="ari-kpi-liquidity-note" class="ari-kpi-note">كل عملة مستقلة</div></div>
            <div class="ari-kpi"><div class="ari-kpi-label">إشارات تحتاج تدخلاً</div><div id="ari-kpi-actions" class="ari-kpi-value">—</div><div id="ari-kpi-actions-note" class="ari-kpi-note">من المصادر الرقابية الحية</div></div>
        </section>

        <section class="ari-card mb-4">
            <div class="ari-card-head"><div><h5>مقارنة بالفترة السابقة</h5><div class="small text-muted mt-1">الفترة السابقة مساوية في عدد الأيام وتنتهي قبل بداية الفترة الحالية بيوم</div></div><span class="ari-status neutral">اتجاهات تشغيلية</span></div>
            <div class="ari-card-body">
                <div class="ari-grid-4">
                    <div class="ari-trend"><div class="ari-trend-title">عدد المعاملات الأصلية</div><div class="ari-trend-main"><div id="ari-trend-transactions-current" class="ari-trend-value">—</div><span id="ari-trend-transactions-delta" class="ari-delta neutral">—</span></div><div id="ari-trend-transactions-previous" class="ari-trend-prev">السابق: —</div><div class="ari-compare-bar"><span id="ari-trend-transactions-bar"></span></div></div>
                    <div class="ari-trend"><div class="ari-trend-title">الاستثناءات المالية</div><div class="ari-trend-main"><div id="ari-trend-exceptions-current" class="ari-trend-value">—</div><span id="ari-trend-exceptions-delta" class="ari-delta neutral">—</span></div><div id="ari-trend-exceptions-previous" class="ari-trend-prev">السابق: —</div><div class="ari-compare-bar"><span id="ari-trend-exceptions-bar"></span></div></div>
                    <div class="ari-trend"><div class="ari-trend-title">تذاكر الدعم الجديدة</div><div class="ari-trend-main"><div id="ari-trend-support-current" class="ari-trend-value">—</div><span id="ari-trend-support-delta" class="ari-delta neutral">—</span></div><div id="ari-trend-support-previous" class="ari-trend-prev">السابق: —</div><div class="ari-compare-bar"><span id="ari-trend-support-bar"></span></div></div>
                    <div class="ari-trend"><div class="ari-trend-title">محاولات الدخول الفاشلة</div><div class="ari-trend-main"><div id="ari-trend-auth-current" class="ari-trend-value">—</div><span id="ari-trend-auth-delta" class="ari-delta neutral">—</span></div><div id="ari-trend-auth-previous" class="ari-trend-prev">السابق: —</div><div class="ari-compare-bar"><span id="ari-trend-auth-bar"></span></div></div>
                </div>
                <div class="ari-note mt-3">ارتفاع حجم المعاملات أو تذاكر الدعم ليس مصنفاً تلقائياً كجيد أو سيئ. أما ارتفاع الاستثناءات المالية أو فشل المصادقة فيظهر كمؤشر سلبي لأنه يمثل خطراً تشغيلياً قابلاً للقياس.</div>
            </div>
        </section>

        <section class="ari-card mb-4">
            <div class="ari-card-head"><div><h5>مركز القرارات والتنبيهات</h5><div class="small text-muted mt-1">إشارات مشتقة فقط من القيم الحقيقية التي تعيدها التقارير الحالية</div></div><span class="ari-status neutral">Action Center</span></div>
            <div class="ari-card-body"><div id="ari-actions" class="ari-action-list"><div class="ari-action-empty">جارٍ تحليل المؤشرات…</div></div></div>
        </section>

        <section class="ari-grid-2 mb-4">
            <div class="ari-card">
                <div class="ari-card-head"><div><h5>المعاملات والسيولة</h5><div class="small text-muted mt-1">لا يوجد إجمالي نقدي مضلل بين العملات</div></div><span id="ari-finance-state" class="ari-status neutral">تحميل</span></div>
                <div class="ari-card-body"><div class="row g-4"><div class="col-lg-6"><div class="fw-bold mb-2">حجم الحركة حسب العملة</div><div id="ari-volume-list"></div></div><div class="col-lg-6"><div class="fw-bold mb-2">تغطية المحافظ حسب العملة</div><div id="ari-liquidity-list"></div></div></div></div>
            </div>
            <div class="ari-card">
                <div class="ari-card-head"><h5>الاستثناءات المالية</h5><span id="ari-exception-state" class="ari-status neutral">تحميل</span></div>
                <div class="ari-card-body"><div class="ari-grid-3"><div class="ari-mini"><span>معلقة متأخرة</span><strong id="ari-ex-pending">—</strong></div><div class="ari-mini"><span>مرفوضة / فاشلة</span><strong id="ari-ex-rejected">—</strong></div><div class="ari-mini"><span>إجمالي الإشارات</span><strong id="ari-ex-total">—</strong></div></div><div class="ari-note mt-3">الأرقام هنا عدادات حالات وليست مبالغ مالية، لذلك لا يوجد خلط عملات.</div></div>
            </div>
        </section>

        <section class="ari-grid-3 mb-4">
            <div class="ari-card"><div class="ari-card-head"><h5>التجار والاشتراكات</h5><span id="ari-merchant-state" class="ari-status neutral">تحميل</span></div><div class="ari-card-body"><div class="ari-grid-2"><div class="ari-mini"><span>إجمالي التجار</span><strong id="ari-merchant-total">—</strong></div><div class="ari-mini"><span>موثقون</span><strong id="ari-merchant-verified">—</strong></div><div class="ari-mini"><span>اشتراكات منتهية</span><strong id="ari-merchant-expired">—</strong></div><div class="ari-mini"><span>تنتهي خلال 30 يوم</span><strong id="ari-merchant-expiring">—</strong></div></div></div></div>
            <div class="ari-card"><div class="ari-card-head"><h5>KYC والتحقق</h5><span id="ari-kyc-state" class="ari-status neutral">تحميل</span></div><div class="ari-card-body"><div class="ari-grid-2"><div class="ari-mini"><span>عملاء موثقون</span><strong id="ari-kyc-verified">—</strong></div><div class="ari-mini"><span>إجمالي العملاء</span><strong id="ari-kyc-total">—</strong></div><div class="ari-mini"><span>تراكم التجار</span><strong id="ari-kyc-backlog">—</strong></div><div class="ari-mini"><span>متأخر 8+ أيام</span><strong id="ari-kyc-aged">—</strong></div></div></div></div>
            <div class="ari-card"><div class="ari-card-head"><h5>الدعم وSLA</h5><span id="ari-support-state" class="ari-status neutral">تحميل</span></div><div class="ari-card-body"><div class="ari-grid-2"><div class="ari-mini"><span>تراكم مفتوح</span><strong id="ari-support-open">—</strong></div><div class="ari-mini"><span>عاجلة</span><strong id="ari-support-urgent">—</strong></div><div class="ari-mini"><span>غير مسندة</span><strong id="ari-support-unassigned">—</strong></div><div class="ari-mini"><span>خرق SLA</span><strong id="ari-support-sla">—</strong></div></div></div></div>
        </section>

        <section class="ari-grid-2 mb-4">
            <div class="ari-card"><div class="ari-card-head"><h5>أمان المصادقة</h5><span id="ari-auth-state" class="ari-status neutral">تحميل</span></div><div class="ari-card-body"><div class="ari-grid-2"><div class="ari-mini"><span>محاولات الدخول</span><strong id="ari-auth-attempts">—</strong></div><div class="ari-mini"><span>فاشلة</span><strong id="ari-auth-failed">—</strong></div><div class="ari-mini"><span>نسبة النجاح</span><strong id="ari-auth-rate">—</strong></div><div class="ari-mini"><span>مصادر فشل متكرر</span><strong id="ari-auth-repeated">—</strong></div></div><div class="ari-note mt-3">تجميعي فقط: لا Identifier ولا IP ولا User-Agent ولا OTP أو Hash أو Token.</div></div></div>
            <div class="ari-card"><div class="ari-card-head"><h5>صحة المنصة</h5><span id="ari-health-state" class="ari-status neutral">تحميل</span></div><div class="ari-card-body"><div class="ari-grid-2"><div class="ari-mini"><span>نبض المراقبة</span><strong id="ari-health-heartbeat">—</strong></div><div class="ari-mini"><span>المكونات المرصودة</span><strong id="ari-health-components">—</strong></div><div class="ari-mini"><span>مكونات متوقفة</span><strong id="ari-health-down">—</strong></div><div class="ari-mini"><span>آخر نبضة</span><strong id="ari-health-last">—</strong></div></div></div></div>
        </section>

        <section id="ari-explorer" class="ari-card mb-4">
            <div class="ari-card-head"><div><h5>Drill-down · مستكشف التقارير</h5><div class="small text-muted mt-1">أي تنبيه في مركز القرارات يستطيع فتح مصدره هنا مباشرة</div></div><span id="ari-explorer-state" class="ari-status neutral">اختر تقريراً</span></div>
            <div class="ari-card-body">
                <div class="ari-report-buttons mb-3">
                    <button type="button" class="ari-report-btn" data-report="trial-balance">ميزان المراجعة</button><button type="button" class="ari-report-btn" data-report="income-statement">قائمة الدخل</button><button type="button" class="ari-report-btn" data-report="balance-sheet">الميزانية</button><button type="button" class="ari-report-btn" data-report="cash-flow">التدفق النقدي</button><button type="button" class="ari-report-btn" data-report="liquidity">السيولة</button><button type="button" class="ari-report-btn" data-report="safeguarded-funds">غطاء أموال العملاء</button><button type="button" class="ari-report-btn" data-report="general-ledger">دفتر الأستاذ</button><button type="button" class="ari-report-btn" data-report="fees-commissions">الرسوم والعمولات</button><button type="button" class="ari-report-btn" data-report="transaction-volume">حجم المعاملات</button><button type="button" class="ari-report-btn" data-report="transaction-exceptions">الاستثناءات</button><button type="button" class="ari-report-btn" data-report="reconciliation">المطابقة</button><button type="button" class="ari-report-btn" data-report="credit-control">الديون والتحصيل</button><button type="button" class="ari-report-btn" data-report="merchant-portfolio">التجار</button><button type="button" class="ari-report-btn" data-report="inventory-control">المخزون</button><button type="button" class="ari-report-btn" data-report="customer-activity">العملاء</button><button type="button" class="ari-report-btn" data-report="agent-liquidity">الوكلاء</button><button type="button" class="ari-report-btn" data-report="subscriptions">الاشتراكات</button><button type="button" class="ari-report-btn" data-report="vertical-performance">القطاعات</button><button type="button" class="ari-report-btn" data-report="kyc-pipeline">KYC</button><button type="button" class="ari-report-btn" data-report="aml-regulatory">AML</button><button type="button" class="ari-report-btn" data-report="audit-sensitive-actions">التدقيق الحساس</button><button type="button" class="ari-report-btn" data-report="rbac-changes">الصلاحيات</button><button type="button" class="ari-report-btn" data-report="system-health-history">صحة النظام</button><button type="button" class="ari-report-btn" data-report="queue-operations">الطوابير</button><button type="button" class="ari-report-btn" data-report="email-otp">البريد وOTP</button><button type="button" class="ari-report-btn" data-report="auth-security">المصادقة</button><button type="button" class="ari-report-btn" data-report="support-operations">الدعم</button>
                </div>
                <div id="ari-explorer-result" class="ari-result-empty">اختر تقريراً لقراءة تفاصيله من نفس مصدر الحقيقة المستخدم في اللوحة.</div>
            </div>
        </section>

        <section class="ari-card">
            <div class="ari-card-head"><div><h5>خريطة جاهزية منظومة التقارير</h5><div class="small text-muted mt-1">الحالة تأتي من Report Catalog ولا تتغير لأغراض تجميل الواجهة</div></div><div class="d-flex flex-wrap gap-2"><span class="ari-status good">جاهز {{ $readyReports }}</span><span class="ari-status warn">جزئي {{ $partialReports }}</span><span class="ari-status bad">ناقص {{ $missingReports }}</span></div></div>
            <div class="ari-card-body"><div class="row g-3">@foreach($catalog as $domain)<div class="col-xl-6"><div class="ari-domain"><div class="ari-domain-title">{{ $domain['label'] }}</div>@foreach($domain['reports'] as $report)@php($s = $report['status'] ?? 'missing')<div class="ari-report-row"><div><div class="ari-report-name">{{ $report['label'] }}</div><div class="ari-report-source">المصدر: {{ $report['source'] }}</div></div><span class="ari-status {{ $s === 'ready' ? 'good' : ($s === 'partial' ? 'warn' : 'bad') }}">{{ $s === 'ready' ? 'جاهز' : ($s === 'partial' ? 'جزئي' : 'ناقص') }}</span></div>@endforeach</div></div>@endforeach</div></div>
        </section>
    </div>
</div>
@endsection

@push('script')
<script src="{{ asset('assets/admin/js/amial-reporting-intelligence.js') }}" defer></script>
@endpush
