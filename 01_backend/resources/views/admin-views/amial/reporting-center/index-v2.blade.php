@extends('layouts.admin.app')

@section('title', 'مركز التقارير التنفيذي')

@php
    $totalReports = max(0, (int) ($summary['total'] ?? 0));
    $readyReports = max(0, (int) ($summary['ready'] ?? 0));
    $partialReports = max(0, (int) ($summary['partial'] ?? 0));
    $missingReports = max(0, (int) ($summary['missing'] ?? 0));
    $readinessPct = $totalReports > 0 ? round(($readyReports / $totalReports) * 100) : 0;
@endphp

@push('css_or_js')
<style>
    .arc{--arc-bg:#f5f7fb;--arc-card:#fff;--arc-text:#172033;--arc-muted:#667085;--arc-border:#e6eaf0;--arc-soft:#f8fafc;--arc-good:#168a5b;--arc-warn:#b7791f;--arc-bad:#c73b4a;--arc-blue:#3157d5;direction:rtl;color:var(--arc-text)}
    .arc *{box-sizing:border-box}.arc-shell{background:var(--arc-bg);border-radius:24px;padding:18px;min-height:calc(100vh - 120px)}
    .arc-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#111c3f 0%,#1f3f92 58%,#3157d5 100%);border-radius:24px;color:#fff;padding:28px;box-shadow:0 18px 50px rgba(31,63,146,.18)}
    .arc-hero:after{content:"";position:absolute;width:320px;height:320px;border-radius:50%;background:rgba(255,255,255,.08);left:-110px;top:-160px}.arc-hero:before{content:"";position:absolute;width:180px;height:180px;border:1px solid rgba(255,255,255,.15);border-radius:50%;left:120px;bottom:-120px}
    .arc-eyebrow{font-size:.78rem;letter-spacing:.08em;opacity:.72}.arc-hero h2{font-weight:900;margin:6px 0 8px;font-size:clamp(1.55rem,2.6vw,2.25rem)}.arc-hero p{max-width:760px;margin:0;color:rgba(255,255,255,.78)}
    .arc-hero-actions{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:2}.arc-chip{display:inline-flex;gap:7px;align-items:center;border-radius:999px;padding:8px 12px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);font-size:.82rem}.arc-dot{width:8px;height:8px;border-radius:50%;background:#63e6be;box-shadow:0 0 0 4px rgba(99,230,190,.14)}
    .arc-panel{background:var(--arc-card);border:1px solid var(--arc-border);border-radius:18px;box-shadow:0 8px 30px rgba(16,24,40,.045)}.arc-panel-head{padding:18px 20px;border-bottom:1px solid var(--arc-border);display:flex;align-items:center;justify-content:space-between;gap:12px}.arc-panel-head h5{font-weight:850;margin:0}.arc-panel-body{padding:20px}
    .arc-filter{margin-top:-18px;position:relative;z-index:5}.arc-filter .arc-panel{box-shadow:0 16px 40px rgba(16,24,40,.09)}.arc-label{font-size:.78rem;color:var(--arc-muted);font-weight:700;margin-bottom:6px}.arc-filter .form-control{height:44px;border-radius:12px;border-color:var(--arc-border)}
    .arc-btn{border-radius:12px;font-weight:750;padding:10px 15px}.arc-btn-primary{background:var(--arc-blue);color:#fff;border:1px solid var(--arc-blue)}.arc-btn-soft{background:var(--arc-soft);color:var(--arc-text);border:1px solid var(--arc-border)}.arc-btn:hover{filter:brightness(.98);text-decoration:none}
    .arc-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.arc-kpi{background:var(--arc-card);border:1px solid var(--arc-border);border-radius:18px;padding:18px;min-height:128px;position:relative;overflow:hidden}.arc-kpi:after{content:"";position:absolute;inset-inline-end:-24px;top:-26px;width:88px;height:88px;border-radius:50%;background:var(--arc-soft)}.arc-kpi-title{color:var(--arc-muted);font-size:.8rem;font-weight:750}.arc-kpi-value{font-size:1.7rem;font-weight:900;margin:8px 0 5px;direction:ltr;text-align:right;position:relative;z-index:1}.arc-kpi-note{font-size:.75rem;color:var(--arc-muted)}
    .arc-status{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 9px;font-size:.72rem;font-weight:800}.arc-status.good{background:#eaf8f2;color:var(--arc-good)}.arc-status.warn{background:#fff7e7;color:var(--arc-warn)}.arc-status.bad{background:#fff0f2;color:var(--arc-bad)}.arc-status.neutral{background:#f2f4f7;color:#475467}
    .arc-grid-2{display:grid;grid-template-columns:1.15fr .85fr;gap:16px}.arc-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.arc-mini-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.arc-mini{border:1px solid var(--arc-border);background:var(--arc-soft);border-radius:14px;padding:13px}.arc-mini span{display:block;color:var(--arc-muted);font-size:.74rem}.arc-mini strong{display:block;margin-top:5px;font-size:1.1rem;direction:ltr;text-align:right}
    .arc-list{display:flex;flex-direction:column;gap:9px}.arc-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid var(--arc-border)}.arc-row:last-child{border-bottom:0}.arc-row-title{font-weight:750;font-size:.86rem}.arc-row-sub{font-size:.72rem;color:var(--arc-muted);margin-top:2px}.arc-row-value{font-weight:850;direction:ltr;white-space:nowrap}
    .arc-progress{height:8px;border-radius:99px;background:#edf1f7;overflow:hidden}.arc-progress>span{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#3157d5,#5e7cf0)}
    .arc-health{display:flex;align-items:center;gap:12px}.arc-health-icon{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;font-weight:900}.arc-health-icon.good{background:#eaf8f2;color:var(--arc-good)}.arc-health-icon.warn{background:#fff7e7;color:var(--arc-warn)}.arc-health-icon.bad{background:#fff0f2;color:var(--arc-bad)}
    .arc-domain{border:1px solid var(--arc-border);border-radius:16px;padding:16px;background:#fff;height:100%}.arc-domain-title{font-weight:850;margin-bottom:12px}.arc-report-item{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px dashed var(--arc-border)}.arc-report-item:last-child{border-bottom:0}.arc-report-name{font-size:.83rem;font-weight:750}.arc-report-source{font-size:.69rem;color:var(--arc-muted);margin-top:2px;max-width:360px;overflow-wrap:anywhere}
    .arc-library{display:flex;flex-wrap:wrap;gap:8px}.arc-report-btn{border:1px solid var(--arc-border);background:#fff;border-radius:12px;padding:9px 12px;font-size:.8rem;font-weight:750;color:var(--arc-text);transition:.16s}.arc-report-btn:hover,.arc-report-btn.active{border-color:#9bb0ee;background:#f2f5ff;color:#2447b5;transform:translateY(-1px)}
    .arc-result-empty{border:1px dashed #cbd3df;background:var(--arc-soft);border-radius:16px;padding:30px;text-align:center;color:var(--arc-muted)}.arc-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.arc-metric{border:1px solid var(--arc-border);background:#fff;border-radius:14px;padding:13px}.arc-metric .k{font-size:.72rem;color:var(--arc-muted)}.arc-metric .v{font-weight:850;margin-top:4px;overflow-wrap:anywhere;direction:ltr;text-align:right}.arc-subsection{border-top:1px solid var(--arc-border);padding-top:16px;margin-top:16px}.arc-subsection h6{font-weight:850;margin-bottom:10px}.arc-table{width:100%;font-size:.78rem}.arc-table th{background:#f8fafc;color:#475467;font-weight:800;padding:10px;white-space:nowrap}.arc-table td{padding:10px;border-top:1px solid var(--arc-border);white-space:nowrap}.arc-table-wrap{overflow:auto;border:1px solid var(--arc-border);border-radius:12px}
    .arc-loading{display:inline-flex;gap:5px;align-items:center}.arc-loading i{width:5px;height:5px;border-radius:50%;background:#98a2b3;animation:arcPulse 1.1s infinite ease-in-out}.arc-loading i:nth-child(2){animation-delay:.15s}.arc-loading i:nth-child(3){animation-delay:.3s}@keyframes arcPulse{0%,80%,100%{opacity:.25;transform:scale(.8)}40%{opacity:1;transform:scale(1)}}
    .arc-note{border-inline-start:3px solid #7c95e5;background:#f6f8ff;border-radius:10px;padding:10px 12px;color:#475467;font-size:.76rem}.arc-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr}
    @media(max-width:1199px){.arc-kpis{grid-template-columns:repeat(2,1fr)}.arc-grid-2,.arc-grid-3{grid-template-columns:1fr}.arc-metrics{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:575px){.arc-shell{padding:10px;border-radius:18px}.arc-hero{padding:21px;border-radius:18px}.arc-kpis{grid-template-columns:1fr 1fr;gap:9px}.arc-kpi{padding:14px;min-height:112px}.arc-kpi-value{font-size:1.25rem}.arc-metrics{grid-template-columns:1fr 1fr}.arc-panel-body{padding:15px}.arc-panel-head{padding:15px}}
</style>
@endpush

@section('content')
<div class="container-fluid px-0 arc">
    <div class="arc-shell">
        <section class="arc-hero mb-4">
            <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-4 position-relative" style="z-index:2">
                <div>
                    <div class="arc-eyebrow">AMIAL PAY · EXECUTIVE REPORTING</div>
                    <h2>مركز التقارير والرقابة التنفيذية</h2>
                    <p>رؤية واحدة للمال، السيولة، التشغيل، الهوية والرقابة. كل مؤشر هنا يقرأ من مصدره الحقيقي؛ لا تُجمع العملات المختلفة ولا يتحول المصدر الناقص إلى رقم تجميلي.</p>
                </div>
                <div class="arc-hero-actions">
                    <span class="arc-chip"><span class="arc-dot"></span> بيانات حية من الخادم</span>
                    <span class="arc-chip">جاهز {{ $readyReports }} / {{ $totalReports }}</span>
                    @if($canExport)<span class="arc-chip">صلاحية التصدير مفعلة</span>@endif
                    <a class="arc-chip text-white" href="{{ route('admin.amial.reporting-center.index', ['legacy' => 1]) }}">العرض التشغيلي التفصيلي</a>
                </div>
            </div>
        </section>

        <section class="arc-filter mb-4">
            <div class="arc-panel">
                <div class="arc-panel-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3"><div class="arc-label">من تاريخ</div><input id="arc-from" class="form-control" type="date" value="{{ now()->startOfMonth()->toDateString() }}"></div>
                        <div class="col-md-3"><div class="arc-label">إلى / كما في</div><input id="arc-to" class="form-control" type="date" value="{{ now()->toDateString() }}"></div>
                        <div class="col-md-3"><div class="arc-label">حالة التحديث</div><div id="arc-refresh-state" class="form-control d-flex align-items-center bg-white text-muted">جاهز للتحديث</div></div>
                        <div class="col-md-3 d-flex gap-2"><button id="arc-refresh" type="button" class="arc-btn arc-btn-primary flex-grow-1">تحديث اللوحة</button><a class="arc-btn arc-btn-soft" href="{{ route('admin.amial.reporting-center.index') }}">إعادة</a></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="arc-kpis mb-4">
            <div class="arc-kpi"><div class="arc-kpi-title">جاهزية منظومة التقارير</div><div class="arc-kpi-value">{{ $readinessPct }}%</div><div class="arc-progress"><span style="width:{{ min(100,$readinessPct) }}%"></span></div><div class="arc-kpi-note mt-2">{{ $readyReports }} جاهز · {{ $partialReports }} جزئي · {{ $missingReports }} ناقص</div></div>
            <div class="arc-kpi"><div class="arc-kpi-title">المعاملات الأصلية في الفترة</div><div id="kpi-transactions" class="arc-kpi-value">—</div><div id="kpi-transactions-note" class="arc-kpi-note">يتم التحميل من دفتر الحركة</div></div>
            <div class="arc-kpi"><div class="arc-kpi-title">تغطية السيولة حسب العملات</div><div id="kpi-liquidity" class="arc-kpi-value">—</div><div id="kpi-liquidity-note" class="arc-kpi-note">لا يتم جمع أرصدة العملات المختلفة</div></div>
            <div class="arc-kpi"><div class="arc-kpi-title">الاستثناءات الحرجة</div><div id="kpi-exceptions" class="arc-kpi-value">—</div><div id="kpi-exceptions-note" class="arc-kpi-note">معلّقة + مرفوضة/فاشلة</div></div>
        </section>

        <section class="arc-grid-2 mb-4">
            <div class="arc-panel">
                <div class="arc-panel-head"><div><h5>المشهد المالي والسيولة</h5><div class="small text-muted mt-1">المبالغ تظهر منفصلة حسب العملة</div></div><span id="finance-state" class="arc-status neutral">تحميل</span></div>
                <div class="arc-panel-body">
                    <div class="arc-grid-2">
                        <div><div class="arc-label">حجم المعاملات حسب العملة</div><div id="volume-by-currency" class="arc-list"><div class="text-muted small">جارٍ التحميل…</div></div></div>
                        <div><div class="arc-label">مركز السيولة حسب العملة</div><div id="liquidity-by-currency" class="arc-list"><div class="text-muted small">جارٍ التحميل…</div></div></div>
                    </div>
                    <div class="arc-note mt-3">قاعدة مالية ثابتة: نعرض SAR وYER وأي عملة أخرى كلٌ على حدة. لا يوجد إجمالي نقدي مضلل عبر عملات مختلفة.</div>
                </div>
            </div>
            <div class="arc-panel">
                <div class="arc-panel-head"><h5>الاستثناءات والتحقيق المالي</h5><span id="exception-state" class="arc-status neutral">تحميل</span></div>
                <div class="arc-panel-body"><div class="arc-mini-grid"><div class="arc-mini"><span>معلقة متأخرة</span><strong id="ex-pending">—</strong></div><div class="arc-mini"><span>مرفوضة / فاشلة</span><strong id="ex-rejected">—</strong></div><div class="arc-mini"><span>عملات فيها عكس</span><strong id="ex-reversal-currencies">—</strong></div><div class="arc-mini"><span>حالة المراجعة</span><strong id="ex-state-text" style="font-size:.9rem">—</strong></div></div></div>
            </div>
        </section>

        <section class="arc-grid-3 mb-4">
            <div class="arc-panel"><div class="arc-panel-head"><h5>التجار والتحقق</h5><span id="merchant-state" class="arc-status neutral">تحميل</span></div><div class="arc-panel-body"><div class="arc-mini-grid"><div class="arc-mini"><span>إجمالي التجار</span><strong id="merchant-total">—</strong></div><div class="arc-mini"><span>موثقون</span><strong id="merchant-verified">—</strong></div><div class="arc-mini"><span>منتهية</span><strong id="merchant-expired">—</strong></div><div class="arc-mini"><span>تنتهي خلال 30 يوم</span><strong id="merchant-expiring">—</strong></div></div></div></div>
            <div class="arc-panel"><div class="arc-panel-head"><h5>KYC وهوية العملاء</h5><span id="kyc-state" class="arc-status neutral">تحميل</span></div><div class="arc-panel-body"><div class="arc-mini-grid"><div class="arc-mini"><span>عملاء موثقون</span><strong id="kyc-verified">—</strong></div><div class="arc-mini"><span>إجمالي العملاء</span><strong id="kyc-total">—</strong></div><div class="arc-mini"><span>تراكم التجار</span><strong id="kyc-backlog">—</strong></div><div class="arc-mini"><span>متأخر 8+ أيام</span><strong id="kyc-aged">—</strong></div></div></div></div>
            <div class="arc-panel"><div class="arc-panel-head"><h5>الدعم وSLA</h5><span id="support-state" class="arc-status neutral">تحميل</span></div><div class="arc-panel-body"><div class="arc-mini-grid"><div class="arc-mini"><span>التراكم المفتوح</span><strong id="support-open">—</strong></div><div class="arc-mini"><span>عاجلة</span><strong id="support-urgent">—</strong></div><div class="arc-mini"><span>غير مسندة</span><strong id="support-unassigned">—</strong></div><div class="arc-mini"><span>SLA</span><strong id="support-sla" style="font-size:.9rem">—</strong></div></div></div></div>
        </section>

        <section class="arc-grid-2 mb-4">
            <div class="arc-panel"><div class="arc-panel-head"><h5>أمان المصادقة</h5><span id="auth-state" class="arc-status neutral">تحميل</span></div><div class="arc-panel-body"><div class="arc-mini-grid"><div class="arc-mini"><span>محاولات الدخول</span><strong id="auth-attempts">—</strong></div><div class="arc-mini"><span>فاشلة</span><strong id="auth-failed">—</strong></div><div class="arc-mini"><span>نسبة النجاح</span><strong id="auth-success-rate">—</strong></div><div class="arc-mini"><span>مصادر فشل متكرر</span><strong id="auth-repeated">—</strong></div></div><div class="arc-note mt-3">هذه اللوحة تجميعية فقط؛ لا تعرض Identifier أو IP أو User-Agent أو أسرار المصادقة.</div></div></div>
            <div class="arc-panel"><div class="arc-panel-head"><h5>صحة المنصة</h5><span id="health-state" class="arc-status neutral">تحميل</span></div><div class="arc-panel-body"><div id="health-summary" class="arc-health"><div class="arc-health-icon warn">…</div><div><div class="fw-bold">جارٍ قراءة سجل المراقبة</div><div class="small text-muted">يتم الاعتماد على العينات التاريخية ونبض المراقبة</div></div></div><div id="health-components" class="arc-list mt-3"></div></div></div>
        </section>

        <section class="arc-panel mb-4">
            <div class="arc-panel-head"><div><h5>مستكشف التقارير</h5><div class="small text-muted mt-1">افتح أي تقرير من نفس مصادر الحقيقة دون مغادرة المركز</div></div><span id="explorer-state" class="arc-status neutral">اختر تقريراً</span></div>
            <div class="arc-panel-body">
                <div class="arc-library mb-3">
                    <button class="arc-report-btn" data-report="trial-balance">ميزان المراجعة</button><button class="arc-report-btn" data-report="income-statement">قائمة الدخل</button><button class="arc-report-btn" data-report="balance-sheet">الميزانية</button><button class="arc-report-btn" data-report="cash-flow">التدفق النقدي</button><button class="arc-report-btn" data-report="liquidity">السيولة</button><button class="arc-report-btn" data-report="safeguarded-funds">غطاء أموال العملاء</button><button class="arc-report-btn" data-report="general-ledger">دفتر الأستاذ</button><button class="arc-report-btn" data-report="fees-commissions">الرسوم والعمولات</button><button class="arc-report-btn" data-report="transaction-volume">حجم المعاملات</button><button class="arc-report-btn" data-report="transaction-exceptions">الاستثناءات</button><button class="arc-report-btn" data-report="reconciliation">المطابقة</button><button class="arc-report-btn" data-report="credit-control">الديون والتحصيل</button><button class="arc-report-btn" data-report="merchant-portfolio">التجار</button><button class="arc-report-btn" data-report="inventory-control">المخزون</button><button class="arc-report-btn" data-report="customer-activity">العملاء</button><button class="arc-report-btn" data-report="agent-liquidity">الوكلاء</button><button class="arc-report-btn" data-report="subscriptions">الاشتراكات</button><button class="arc-report-btn" data-report="vertical-performance">القطاعات</button><button class="arc-report-btn" data-report="kyc-pipeline">KYC</button><button class="arc-report-btn" data-report="aml-regulatory">AML</button><button class="arc-report-btn" data-report="audit-sensitive-actions">التدقيق الحساس</button><button class="arc-report-btn" data-report="rbac-changes">الصلاحيات</button><button class="arc-report-btn" data-report="system-health-history">صحة النظام</button><button class="arc-report-btn" data-report="queue-operations">الطوابير</button><button class="arc-report-btn" data-report="email-otp">البريد وOTP</button><button class="arc-report-btn" data-report="auth-security">المصادقة</button><button class="arc-report-btn" data-report="support-operations">الدعم</button>
                </div>
                <div id="explorer-result" class="arc-result-empty">اختر تقريراً من الأعلى. سيظهر هنا ملخص منظم للحقول الحقيقية التي يعيدها الخادم.</div>
            </div>
        </section>

        <section class="arc-panel">
            <div class="arc-panel-head"><div><h5>خريطة جاهزية التقارير</h5><div class="small text-muted mt-1">الحالة أدناه تأتي من كتالوج التقارير نفسه وليست تقديراً من الواجهة</div></div><div class="d-flex gap-2"><span class="arc-status good">جاهز {{ $readyReports }}</span><span class="arc-status warn">جزئي {{ $partialReports }}</span><span class="arc-status bad">ناقص {{ $missingReports }}</span></div></div>
            <div class="arc-panel-body"><div class="row g-3">@foreach($catalog as $domain)<div class="col-xl-6"><div class="arc-domain"><div class="arc-domain-title">{{ $domain['label'] }}</div>@foreach($domain['reports'] as $report)@php($s=$report['status'] ?? 'missing')<div class="arc-report-item"><div><div class="arc-report-name">{{ $report['label'] }}</div><div class="arc-report-source">{{ $report['source'] }}</div></div><span class="arc-status {{ $s === 'ready' ? 'good' : ($s === 'partial' ? 'warn' : 'bad') }}">{{ $s === 'ready' ? 'جاهز' : ($s === 'partial' ? 'جزئي' : 'ناقص') }}</span></div>@endforeach</div></div>@endforeach</div></div>
        </section>
    </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const routes = {
        'trial-balance': @json(route('admin.amial.reporting-center.trial-balance')),
        'income-statement': @json(route('admin.amial.reporting-center.income-statement')),
        'balance-sheet': @json(route('admin.amial.reporting-center.balance-sheet')),
        'cash-flow': @json(route('admin.amial.reporting-center.cash-flow')),
        'liquidity': @json(route('admin.amial.reporting-center.liquidity')),
        'safeguarded-funds': @json(route('admin.amial.reporting-center.safeguarded-funds')),
        'transaction-volume': @json(route('admin.amial.reporting-center.transaction-volume')),
        'transaction-exceptions': @json(route('admin.amial.reporting-center.transaction-exceptions')),
        'general-ledger': @json(route('admin.amial.reporting-center.general-ledger')),
        'fees-commissions': @json(route('admin.amial.reporting-center.fees-commissions')),
        'reconciliation': @json(route('admin.amial.reporting-center.reconciliation')),
        'merchant-portfolio': @json(route('admin.amial.reporting-center.merchant-portfolio')),
        'inventory-control': @json(route('admin.amial.reporting-center.inventory-control')),
        'credit-control': @json(route('admin.amial.reporting-center.credit-control')),
        'vertical-performance': @json(route('admin.amial.reporting-center.vertical-performance')),
        'kyc-pipeline': @json(route('admin.amial.reporting-center.kyc-pipeline')),
        'agent-liquidity': @json(route('admin.amial.reporting-center.agent-liquidity')),
        'audit-sensitive-actions': @json(route('admin.amial.reporting-center.audit-sensitive-actions')),
        'rbac-changes': @json(route('admin.amial.reporting-center.rbac-changes')),
        'subscriptions': @json(route('admin.amial.reporting-center.subscriptions')),
        'customer-activity': @json(route('admin.amial.reporting-center.customer-activity')),
        'support-operations': @json(route('admin.amial.reporting-center.support-operations')),
        'system-health-history': @json(route('admin.amial.reporting-center.system-health-history')),
        'queue-operations': @json(route('admin.amial.reporting-center.queue-operations')),
        'email-otp': @json(route('admin.amial.reporting-center.email-otp')),
        'auth-security': @json(route('admin.amial.reporting-center.auth-security')),
        'aml-regulatory': @json(route('admin.amial.reporting-center.aml-regulatory')),
    };
    const asOfReports = new Set(['balance-sheet','liquidity','safeguarded-funds']);
    const noPeriodReports = new Set(['merchant-portfolio','credit-control','reconciliation','aml-regulatory']);
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>\"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[c]));
    const fmt = value => {
        if (value === null || value === undefined || value === '') return '—';
        const raw = String(value); if (!/^-?\d+(\.\d+)?$/.test(raw)) return raw;
        const neg = raw.startsWith('-'), clean = neg ? raw.slice(1) : raw, parts = clean.split('.');
        const whole = (parts[0] || '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const fraction = (parts[1] || '').replace(/0+$/, '');
        return (neg ? '-' : '') + whole + (fraction ? '.' + fraction : '');
    };
    const loading = () => '<span class="arc-loading"><i></i><i></i><i></i></span>';
    const status = (id, text, kind='neutral') => { const n=$(id); if(!n)return; n.className='arc-status '+kind; n.textContent=text; };
    const set = (id, value) => { const n=$(id); if(n)n.textContent=value ?? '—'; };
    const queryFor = name => {
        const q = new URLSearchParams(), from=$('arc-from').value, to=$('arc-to').value;
        if (name === 'agent-liquidity') q.set('date',to);
        else if (asOfReports.has(name)) q.set('as_of',to);
        else if (!noPeriodReports.has(name)) { if(from)q.set('from',from); if(to)q.set('to',to); }
        return q.toString();
    };
    async function fetchMeta(name){
        const url = routes[name] + (queryFor(name) ? '?' + queryFor(name) : '');
        const res = await fetch(url,{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        let body={}; try{body=await res.json();}catch(e){}
        if(!res.ok || body.success===false) throw new Error(body.message || `HTTP ${res.status}`);
        return body.meta ?? {};
    }
    function badgeText(ok){ return ok ? ['سليم','good'] : ['يتطلب انتباهاً','bad']; }
    function renderVolume(meta){
        const rows=Array.isArray(meta.by_currency)?meta.by_currency:[];
        const entries=rows.reduce((n,r)=>n+Number(r.original_entries||0),0); set('kpi-transactions',fmt(entries)); set('kpi-transactions-note',`${rows.length} عملة ممثلة في الفترة`);
        $('volume-by-currency').innerHTML = rows.length ? rows.map(r=>`<div class="arc-row"><div><div class="arc-row-title">${esc(r.currency||'—')}</div><div class="arc-row-sub">${fmt(r.original_entries||0)} قيد أصلي · ${fmt(r.reversal_entries||0)} عكسي</div></div><div class="arc-row-value">${esc(fmt(r.gross_original_volume||0))}</div></div>`).join('') : '<div class="text-muted small">لا توجد معاملات في الفترة.</div>';
    }
    function renderLiquidity(meta){
        const rows=Array.isArray(meta.by_currency)?meta.by_currency:[]; const covered=rows.filter(r=>r.external_wallets_covered===true).length, gaps=rows.length-covered;
        set('kpi-liquidity',rows.length?`${covered}/${rows.length}`:'—'); set('kpi-liquidity-note',rows.length?(gaps?`${gaps} عملة تحتاج تغطية`:'كل العملات الممثلة مغطاة'):'لا توجد بيانات');
        $('liquidity-by-currency').innerHTML=rows.length?rows.map(r=>`<div class="arc-row"><div><div class="arc-row-title">${esc(r.currency||'—')}</div><div class="arc-row-sub">نسبة التغطية ${esc(fmt(r.external_wallet_coverage_ratio??'—'))}%</div></div><span class="arc-status ${r.external_wallets_covered?'good':'bad'}">${r.external_wallets_covered?'مغطاة':'فجوة'}</span></div>`).join(''):'<div class="text-muted small">لا توجد أرصدة سيولة.</div>';
        status('finance-state',gaps?'توجد فجوات':'سليم',gaps?'bad':'good');
    }
    function renderExceptions(meta){
        const p=meta.pending_transfers||{}, j=meta.rejections||{}, r=meta.reversals||{}; const pending=Number(p.overdue_holding||0), rejected=Number(j.count||0), total=pending+rejected;
        set('ex-pending',fmt(pending));set('ex-rejected',fmt(rejected));set('ex-reversal-currencies',fmt((r.by_currency||[]).length));set('ex-state-text',total?'تحقيق مطلوب':'لا توجد إشارات حرجة');
        set('kpi-exceptions',fmt(total));set('kpi-exceptions-note',total?'تحتاج مراجعة تشغيلية':'لا توجد معلقة/مرفوضة في الفترة');status('exception-state',total?'تحقيق مطلوب':'مستقر',total?'bad':'good');
    }
    function renderMerchant(meta){
        set('merchant-total',fmt(meta.total));set('merchant-verified',fmt(meta.verified));set('merchant-expired',fmt(meta.expired));set('merchant-expiring',fmt(meta.expiring_30d));
        const total=Number(meta.total||0), verified=Number(meta.verified||0);status('merchant-state',total?`${Math.round((verified/total)*100)}% موثق`:'لا بيانات', total&&verified<total?'warn':'good');
    }
    function renderKyc(meta){
        const c=meta.customers||{},m=meta.merchants||{},a=m.pending_aging||{};set('kyc-verified',fmt(c.verified));set('kyc-total',fmt(c.total));set('kyc-backlog',fmt(m.pending_backlog));set('kyc-aged',fmt(a['8_plus_days']||0));
        status('kyc-state',Number(a['8_plus_days']||0)>0?'يوجد تأخير':'ضمن المتابعة',Number(a['8_plus_days']||0)>0?'warn':'good');
    }
    function renderSupport(meta){
        set('support-open',fmt(meta.open_backlog));set('support-urgent',fmt(meta.urgent_backlog));set('support-unassigned',fmt(meta.unassigned_backlog));
        const sla=meta.sla_breach_rate; set('support-sla',sla===null||sla===undefined?'غير مفعّل':`${fmt(sla)}%`); status('support-state',Number(meta.urgent_backlog||0)>0?'عاجل يحتاج متابعة':'مستقر',Number(meta.urgent_backlog||0)>0?'warn':'good');
    }
    function renderAuth(meta){
        set('auth-attempts',fmt(meta.attempts));set('auth-failed',fmt(meta.failed));set('auth-success-rate',`${fmt(meta.success_rate_pct??0)}%`);set('auth-repeated',fmt(meta.repeated_failure_sources));
        const bad=Number(meta.repeated_failure_sources||0)>0 || Number(meta.temporary_lockouts||0)>0;status('auth-state',bad?'مؤشرات تستحق المراجعة':'مستقر',bad?'warn':'good');
    }
    function renderHealth(meta){
        const stale=meta.heartbeat_stale===true, comps=Array.isArray(meta.components)?meta.components:[];status('health-state',stale?'نبض متأخر':'نبض سليم',stale?'bad':'good');
        $('health-summary').innerHTML=`<div class="arc-health-icon ${stale?'bad':'good'}">${stale?'!':'✓'}</div><div><div class="fw-bold">${stale?'نبض المراقبة متأخر أو مفقود':'المراقبة تعمل ضمن النافذة المتوقعة'}</div><div class="small text-muted">آخر نبضة: ${esc(meta.last_heartbeat_at||'—')}</div></div>`;
        $('health-components').innerHTML=comps.slice(0,5).map(c=>`<div class="arc-row"><div><div class="arc-row-title">${esc(c.component)}</div><div class="arc-row-sub">${fmt(c.samples||0)} عينة</div></div><span class="arc-status ${c.latest_state==='up'?'good':(c.latest_state==='down'?'bad':'warn')}">${esc(c.latest_state||'—')}</span></div>`).join('');
    }
    async function loadExecutive(){
        $('arc-refresh-state').innerHTML=loading()+'<span class="ms-2">تحديث البيانات الحية</span>'; $('arc-refresh').disabled=true;
        const tasks=[['transaction-volume',renderVolume],['liquidity',renderLiquidity],['transaction-exceptions',renderExceptions],['merchant-portfolio',renderMerchant],['kyc-pipeline',renderKyc],['support-operations',renderSupport],['auth-security',renderAuth],['system-health-history',renderHealth]];
        const results=await Promise.allSettled(tasks.map(async ([name,fn])=>{const meta=await fetchMeta(name);if(meta.available===false)throw new Error(meta.reason||'المصدر غير متاح');fn(meta);}));
        const failed=results.filter(x=>x.status==='rejected').length; $('arc-refresh-state').textContent=failed?`اكتمل مع ${failed} مصدر غير متاح`:`تم التحديث بنجاح · ${new Date().toLocaleTimeString('ar-SA',{hour:'2-digit',minute:'2-digit'})}`; $('arc-refresh').disabled=false;
        if(failed){['finance-state','exception-state','merchant-state','kyc-state','support-state','auth-state','health-state'].forEach(id=>{const n=$(id);if(n&&n.textContent==='تحميل')status(id,'غير متاح','warn');});}
    }
    function scalarEntries(obj){return Object.entries(obj||{}).filter(([,v])=>v===null||['string','number','boolean'].includes(typeof v)).slice(0,16)}
    function genericRender(meta){
        if(meta.available===false)return `<div class="arc-result-empty">المصدر غير متاح: ${esc(meta.reason||meta.source||'غير محدد')}</div>`;
        const scalars=scalarEntries(meta); let html='';
        if(scalars.length)html+=`<div class="arc-metrics">${scalars.map(([k,v])=>`<div class="arc-metric"><div class="k">${esc(k.replaceAll('_',' '))}</div><div class="v">${esc(typeof v==='boolean'?(v?'نعم':'لا'):fmt(v))}</div></div>`).join('')}</div>`;
        Object.entries(meta||{}).filter(([,v])=>Array.isArray(v)&&v.length).slice(0,5).forEach(([k,rows])=>{
            if(typeof rows[0] !== 'object' || Array.isArray(rows[0]))return; const heads=Object.keys(rows[0]).slice(0,8);
            html+=`<div class="arc-subsection"><h6>${esc(k.replaceAll('_',' '))}</h6><div class="arc-table-wrap"><table class="arc-table"><thead><tr>${heads.map(h=>`<th>${esc(h.replaceAll('_',' '))}</th>`).join('')}</tr></thead><tbody>${rows.slice(0,30).map(r=>`<tr>${heads.map(h=>`<td>${esc(typeof r[h]==='object'?JSON.stringify(r[h]):fmt(r[h]))}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>`;
        });
        Object.entries(meta||{}).filter(([,v])=>v&&typeof v==='object'&&!Array.isArray(v)).slice(0,5).forEach(([k,v])=>{const e=scalarEntries(v);if(!e.length)return;html+=`<div class="arc-subsection"><h6>${esc(k.replaceAll('_',' '))}</h6><div class="arc-metrics">${e.map(([sk,sv])=>`<div class="arc-metric"><div class="k">${esc(sk.replaceAll('_',' '))}</div><div class="v">${esc(typeof sv==='boolean'?(sv?'نعم':'لا'):fmt(sv))}</div></div>`).join('')}</div></div>`;});
        return html||'<div class="arc-result-empty">أعاد الخادم تقريراً بلا حقول قابلة للعرض المختصر. استخدم العرض التشغيلي التفصيلي.</div>';
    }
    document.querySelectorAll('.arc-report-btn').forEach(btn=>btn.addEventListener('click',async()=>{
        document.querySelectorAll('.arc-report-btn').forEach(b=>b.classList.remove('active'));btn.classList.add('active');const name=btn.dataset.report;status('explorer-state','تحميل','neutral');$('explorer-result').innerHTML=`<div class="arc-result-empty">${loading()}<div class="mt-2">جارٍ قراءة التقرير من مصدره الحقيقي…</div></div>`;
        try{const meta=await fetchMeta(name);$('explorer-result').innerHTML=genericRender(meta);status('explorer-state','تم التحديث','good');}catch(e){$('explorer-result').innerHTML=`<div class="arc-result-empty text-danger">تعذر تحميل التقرير: ${esc(e.message)}</div>`;status('explorer-state','خطأ','bad');}
    }));
    $('arc-refresh').addEventListener('click',loadExecutive); $('arc-from').addEventListener('change',()=>status('explorer-state','الفترة تغيرت','neutral')); $('arc-to').addEventListener('change',()=>status('explorer-state','الفترة تغيرت','neutral'));
    loadExecutive();
})();
</script>
@endpush
