@extends('layouts.admin.app')

@section('title', 'مركز التقارير')

@push('css_or_js')
<style>
    .reporting-center .hero { background:linear-gradient(135deg,var(--amial-primary) 0%,var(--amial-primary-light) 100%); color:var(--amial-surface); border:0; }
    .reporting-center .report-toolbar { border:1px solid var(--amial-border,#e7ebf3); border-radius:16px; background:var(--amial-surface,#fff); }
    .reporting-center .metric { border:1px solid var(--amial-border,#e7ebf3); border-radius:12px; padding:12px; height:100%; background:var(--amial-surface,#fff); }
    .reporting-center .metric .value { font-size:1.05rem; font-weight:800; direction:ltr; text-align:right; overflow-wrap:anywhere; }
    .reporting-center .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; direction:ltr; }
    .reporting-center .table td,.reporting-center .table th { vertical-align:middle; white-space:nowrap; }
    .reporting-center .report-buttons .btn { min-width:142px; }
    .reporting-center .source-note { border-inline-start:3px solid var(--amial-primary); padding-inline-start:10px; }
    .reporting-center .section-label { font-weight:800; font-size:.86rem; color:var(--amial-text-secondary,#637083); margin-bottom:.55rem; }
    .reporting-center .catalog-card { border:1px solid var(--amial-border,#e7ebf3); border-radius:16px; }
</style>
@endpush

@section('content')
<div class="container-fluid px-0 reporting-center">
    <div class="card hero shadow-sm mb-4">
        <div class="card-body p-4 p-lg-5">
            <div class="d-flex flex-column flex-xl-row justify-content-between gap-4 align-items-xl-center">
                <div>
                    <div class="small opacity-75 mb-1">AMIAL REPORTING CENTER</div>
                    <h2 class="mb-2">📊 مركز التقارير المؤسسية</h2>
                    <p class="mb-0 opacity-75">المال من الدفتر، والتشغيل من سجلاته الأصلية، والرقابة من سجل التدقيق. لا تقدير صامت ولا جمع عملات.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge bg-light text-dark p-2">جاهز {{ $summary['ready'] }}</span>
                    <span class="badge bg-warning text-dark p-2">جزئي {{ $summary['partial'] }}</span>
                    <span class="badge bg-danger p-2">ناقص {{ $summary['missing'] }}</span>
                    @if($canExport)<span class="badge bg-info text-dark p-2">التصدير مصرح</span>@endif
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info shadow-sm border-0 mb-4">
        <strong>قاعدة المركز:</strong> كل رقم قابل للتتبع إلى مصدر الحقيقة. التقرير الذي ينقصه مصدر أو سياسة رسمية يبقى «جزئياً/ناقصاً» ولا يتحول إلى صفر مطمئن.
    </div>

    <div class="report-toolbar shadow-sm p-3 p-lg-4 mb-4">
        <div class="row g-3 align-items-end mb-4">
            <div class="col-md-4">
                <label class="form-label">من</label>
                <input id="report-from" type="date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">إلى / كما في</label>
                <input id="report-to" type="date" class="form-control" value="{{ now()->toDateString() }}">
            </div>
            <div class="col-md-4">
                <div class="small text-muted source-note">التقارير الزمنية تستخدم الفترة. الميزانية والسيولة تستخدم تاريخ «كما في». سيولة الوكيل تستخدم تاريخ اليوم المحدد.</div>
            </div>
        </div>

        <div class="section-label">القلب المالي P0</div>
        <div class="report-buttons d-flex flex-wrap gap-2 mb-4">
            <button class="btn btn-primary" data-report="trial-balance">ميزان المراجعة</button>
            <button class="btn btn-outline-primary" data-report="income-statement">قائمة الدخل</button>
            <button class="btn btn-outline-primary" data-report="balance-sheet">الميزانية</button>
            <button class="btn btn-outline-primary" data-report="cash-flow">التدفق النقدي</button>
            <button class="btn btn-outline-success" data-report="liquidity">مركز السيولة</button>
            <button class="btn btn-outline-success" data-report="safeguarded-funds">غطاء أموال العملاء</button>
            <button class="btn btn-outline-dark" data-report="general-ledger">دفتر الأستاذ</button>
            <button class="btn btn-outline-dark" data-report="fees-commissions">الرسوم والعمولات</button>
            <button class="btn btn-outline-dark" data-report="transaction-volume">حجم المعاملات</button>
            <button class="btn btn-outline-danger" data-report="transaction-exceptions">الاستثناءات المالية</button>
            <button class="btn btn-outline-secondary" data-report="reconciliation">مطابقة المحافظ</button>
        </div>

        <div class="section-label">الإدارة والرقابة P1</div>
        <div class="report-buttons d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" data-report="merchant-portfolio">محفظة التجار</button>
            <button class="btn btn-outline-primary" data-report="customer-activity">نشاط العملاء</button>
            <button class="btn btn-outline-primary" data-report="agent-liquidity">سيولة الوكلاء</button>
            <button class="btn btn-outline-success" data-report="subscriptions">الاشتراكات</button>
            <button class="btn btn-outline-warning" data-report="kyc-pipeline">KYC والتحقق</button>
            <button class="btn btn-outline-warning" data-report="aml-regulatory">AML وSTR/CTR</button>
            <button class="btn btn-outline-danger" data-report="audit-sensitive-actions">الإجراءات الحساسة</button>
            <button class="btn btn-outline-danger" data-report="rbac-changes">تغييرات الصلاحيات</button>
            <button class="btn btn-outline-secondary" data-report="support-operations">الدعم وزمن الحل</button>
        </div>

        <div id="report-state" class="text-muted small mt-3" aria-live="polite">اختر تقريراً لعرضه.</div>
    </div>

    <div id="report-result" class="mb-4"></div>

    <div class="row g-3">
        @foreach($catalog as $domain)
            <div class="col-xl-6">
                <div class="card catalog-card shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-4 px-4"><h5 class="mb-0">{{ $domain['label'] }}</h5></div>
                    <div class="card-body px-4">
                        <div class="list-group list-group-flush">
                            @foreach($domain['reports'] as $report)
                                @php
                                    $status = $report['status'];
                                    $badge = $status === 'ready' ? 'success' : ($status === 'partial' ? 'warning' : 'danger');
                                    $label = $status === 'ready' ? 'جاهز' : ($status === 'partial' ? 'جزئي' : 'ناقص');
                                @endphp
                                <div class="list-group-item px-0 d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="fw-semibold">{{ $report['label'] }}</div>
                                        <div class="small text-muted">المصدر: {{ $report['source'] }}</div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-{{ $badge }}">{{ $label }}</span>
                                        <div class="small text-muted mt-1">{{ $report['priority'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
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
        'general-ledger': @json(route('admin.amial.reporting-center.general-ledger')),
        'fees-commissions': @json(route('admin.amial.reporting-center.fees-commissions')),
        'transaction-volume': @json(route('admin.amial.reporting-center.transaction-volume')),
        'transaction-exceptions': @json(route('admin.amial.reporting-center.transaction-exceptions')),
        'reconciliation': @json(route('admin.amial.reporting-center.reconciliation')),
        'merchant-portfolio': @json(route('admin.amial.reporting-center.merchant-portfolio')),
        'customer-activity': @json(route('admin.amial.reporting-center.customer-activity')),
        'agent-liquidity': @json(route('admin.amial.reporting-center.agent-liquidity')),
        'subscriptions': @json(route('admin.amial.reporting-center.subscriptions')),
        'kyc-pipeline': @json(route('admin.amial.reporting-center.kyc-pipeline')),
        'aml-regulatory': @json(route('admin.amial.reporting-center.aml-regulatory')),
        'audit-sensitive-actions': @json(route('admin.amial.reporting-center.audit-sensitive-actions')),
        'rbac-changes': @json(route('admin.amial.reporting-center.rbac-changes')),
        'support-operations': @json(route('admin.amial.reporting-center.support-operations')),
    };

    const labels = {
        free:'مجاني', business:'أعمال', enterprise:'مؤسسة', verified:'موثق', pending_review:'قيد المراجعة',
        rejected:'مرفوض', resubmission_required:'إعادة تقديم مطلوبة', verification_suspended:'موقوف التحقق',
        open:'مفتوحة', investigating:'قيد التحقيق', waiting_customer:'بانتظار العميل', resolved:'محلولة', closed:'مغلقة',
        low:'منخفض', normal:'عادي', high:'عالٍ', urgent:'عاجل',
    };

    const state = document.getElementById('report-state');
    const result = document.getElementById('report-result');
    const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    const label = value => labels[value] ?? value ?? '—';
    const money = value => {
        const raw = String(value ?? '0').trim();
        const negative = raw.startsWith('-');
        const clean = negative ? raw.slice(1) : raw;
        const [wholeRaw, fractionRaw = ''] = clean.split('.');
        const whole = (wholeRaw || '0').replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const fraction = fractionRaw.replace(/0+$/, '');
        return (negative ? '-' : '') + whole + (fraction ? '.' + fraction : '');
    };
    const metric = (title, value, suffix = '') => `<div class="col-6 col-lg-3"><div class="metric"><div class="small text-muted">${esc(title)}</div><div class="value">${esc(value ?? '—')}${suffix ? ' ' + esc(suffix) : ''}</div></div></div>`;
    const card = (title, body, subtitle = '', badge = '') => `<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between gap-2"><div><h5 class="mb-1">${esc(title)}</h5>${subtitle ? `<div class="small text-muted">${esc(subtitle)}</div>` : ''}</div>${badge ? `<span class="badge bg-light text-dark mono">${esc(badge)}</span>` : ''}</div><div class="card-body px-4">${body}</div></div>`;
    const table = (heads, rows) => `<div class="table-responsive"><table class="table table-hover table-sm"><thead><tr>${heads.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
    const empty = msg => `<div class="alert alert-light border shadow-sm">${esc(msg)}</div>`;
    const breakdown = rows => table(['القيمة','العدد'], (rows || []).map(r => `<tr><td>${esc(label(r.value))}</td><td class="mono">${esc(r.total)}</td></tr>`));

    function renderTrial(meta) {
        return (meta.by_currency || []).map(c => card('ميزان المراجعة',
            `<div class="row g-2 mb-3">${metric('مدين الفترة',money(c.period_debit))}${metric('دائن الفترة',money(c.period_credit))}${metric('فرق الإقفال',money(c.closing_difference))}${metric('الحالة',c.balanced?'متوازن':'غير متوازن')}</div>` +
            table(['الحساب','الاسم','النوع','افتتاح','مدين','دائن','إقفال'], (c.accounts || []).map(a => `<tr><td class="mono">${esc(a.account_code)}</td><td>${esc(a.name)}</td><td>${esc(a.account_type)}</td><td class="mono">${money(a.opening_balance)}</td><td class="mono">${money(a.period_debit)}</td><td class="mono">${money(a.period_credit)}</td><td class="mono">${money(a.closing_balance)}</td></tr>`)),
            'افتتاح الفترة + الحركة + الإقفال', c.currency)).join('') || empty('لا توجد قيود ضمن الفترة.');
    }

    function renderIncome(meta) {
        return (meta.by_currency || []).map(c => {
            const rows = [...(c.revenue_accounts || []).map(a => ({...a,g:'إيراد'})), ...(c.expense_accounts || []).map(a => ({...a,g:'مصروف'}))];
            return card('قائمة الدخل', `<div class="row g-2 mb-3">${metric('الإيرادات',money(c.revenue))}${metric('المصروفات',money(c.expenses))}${metric('صافي الدخل',money(c.net_income))}${metric('سلامة القيود',c.ledger_balanced?'متوازنة':'اختلال')}</div>` + table(['الفئة','الحساب','الاسم','الرصيد'], rows.map(a => `<tr><td>${a.g}</td><td class="mono">${esc(a.account_code)}</td><td>${esc(a.name)}</td><td class="mono">${money(a.balance)}</td></tr>`)), '', c.currency);
        }).join('') || empty('لا توجد حركة دخل ضمن الفترة.');
    }

    function renderBalance(meta) {
        return (meta.by_currency || []).map(c => {
            const rows = [];
            Object.entries(c.accounts || {}).forEach(([g,accounts]) => (accounts || []).forEach(a => rows.push(`<tr><td>${esc(g)}</td><td class="mono">${esc(a.account_code)}</td><td>${esc(a.name)}</td><td class="mono">${money(a.balance)}</td></tr>`)));
            return card('الميزانية العمومية', `<div class="row g-2 mb-3">${metric('الأصول',money(c.assets))}${metric('الالتزامات',money(c.liabilities))}${metric('حقوق الملكية المعدلة',money(c.equity_with_current_earnings))}${metric('فرق المعادلة',money(c.equation_gap))}</div>` + table(['الفئة','الحساب','الاسم','الرصيد'],rows), c.balanced?'المعادلة متوازنة':'المعادلة تحتاج مراجعة', c.currency);
        }).join('') || empty('لا توجد أرصدة دفترية.');
    }

    function renderCashFlow(meta) {
        return (meta.by_currency || []).map(c => card('التدفق النقدي', `<div class="row g-2">${metric('نقد افتتاحي',money(c.opening_cash))}${metric('تشغيلي',money(c.operating))}${metric('استثماري',money(c.investing))}${metric('تمويلي',money(c.financing))}${metric('غير مصنف',money(c.unclassified))}${metric('صافي التغير',money(c.net_change))}${metric('نقد إقفال',money(c.closing_cash))}${metric('فرق التحقق',money(c.closing_difference))}</div>`, `تغطية التصنيف ${c.classification_coverage_pct ?? '—'}%`, c.currency)).join('') || empty('لا توجد حركة نقدية.');
    }

    function renderLiquidity(meta, safeguard = false) {
        return (meta.by_currency || []).map(c => safeguard
            ? card('غطاء أموال العملاء', `<div class="row g-2">${metric('أموال العملاء',money(c.customer_funds))}${metric('الغطاء السائل',money(c.liquid_cover))}${metric('فائض/عجز العملاء',money(c.customer_surplus_or_shortfall))}${metric('نسبة التغطية',c.customer_coverage_ratio ?? '—','%')}${metric('أموال التجار',money(c.merchant_funds))}${metric('أموال الوكلاء',money(c.agent_funds))}${metric('فائض/عجز المحافظ',money(c.external_wallet_surplus_or_shortfall))}${metric('تغطية المحافظ',c.external_wallet_coverage_ratio ?? '—','%')}</div>`, `الحالة: ${c.customer_status ?? '—'}`, c.currency)
            : card('مركز السيولة', `<div class="row g-2">${metric('الأصول السائلة',money(c.liquid_assets))}${metric('أموال العملاء',money(c.customer_wallets))}${metric('أموال التجار',money(c.merchant_wallets))}${metric('أموال الوكلاء',money(c.agent_wallets))}${metric('التزامات المحافظ',money(c.external_wallet_obligations))}${metric('كل الالتزامات',money(c.total_liabilities))}${metric('فائض/عجز',money(c.liquid_surplus_after_external_wallets))}${metric('نسبة التغطية',c.external_wallet_coverage_ratio ?? '—','%')}</div>`, c.external_wallets_covered?'المحافظ مغطاة':'توجد فجوة تغطية', c.currency)
        ).join('') || empty('لا توجد بيانات سيولة.');
    }

    function renderVolume(meta) {
        return (meta.by_currency || []).map(c => card('حجم المعاملات', `<div class="row g-2 mb-3">${metric('الأصلية',c.original_entries)}${metric('العكسية',c.reversal_entries)}${metric('الحجم الإجمالي',money(c.gross_original_volume))}${metric('الصافي بعد العكس',money(c.net_after_reversals))}</div>` + table(['المصدر','العدد','الحجم','الحالة'],(c.by_source||[]).map(r=>`<tr><td class="mono">${esc(r.source_type)}</td><td>${r.entries}</td><td class="mono">${money(r.volume)}</td><td>${r.is_reversal?'عكسي':'أصلي'}</td></tr>`)),'',c.currency)).join('') || empty('لا توجد معاملات.');
    }

    function renderExceptions(meta) {
        const p=meta.pending_transfers||{}, r=meta.reversals||{}, j=meta.rejections||{};
        return card('الاستثناءات المالية', `<div class="row g-2 mb-3">${metric('معلقة متأخرة',p.overdue_holding??0)}${metric('مرفوضة/فاشلة',j.count??0)}${metric('عملات بها عكس',(r.by_currency||[]).length)}${metric('الفترة',`${meta.from??'—'} → ${meta.to??'—'}`)}</div>` +
            `<div class="row g-3"><div class="col-lg-4">${table(['حالة التحويل','العدد','المبلغ'],(p.summary||[]).map(x=>`<tr><td>${esc(x.status)}</td><td>${x.count}</td><td class="mono">${money(x.amount)}</td></tr>`))}</div><div class="col-lg-4">${table(['قرار الرفض','العدد'],(j.by_code||[]).map(x=>`<tr><td class="mono">${esc(x.decision_code)}</td><td>${x.count}</td></tr>`))}</div><div class="col-lg-4">${table(['العملة','العكس','المبلغ'],(r.by_currency||[]).map(x=>`<tr><td>${esc(x.currency)}</td><td>${x.count}</td><td class="mono">${money(x.amount)}</td></tr>`))}</div></div>`,'المعلقة والفاشلة والعكسية من مصادر حالتها الأصلية');
    }

    function renderGeneralLedger(meta) {
        return card('دفتر الأستاذ', `<div class="row g-2 mb-3">${metric('إجمالي القيود',meta.pagination?.total??0)}${metric('الصفحة',meta.pagination?.page??1)}${metric('عدد الصفحات',meta.pagination?.pages??0)}${metric('غير قابل للتعديل',meta.immutable?'نعم':'لا')}</div>` + table(['الوقت','المرجع','المصدر','العملة','مدين','دائن','الحالة'],(meta.items||[]).map(e=>`<tr><td>${esc(e.posted_at)}</td><td class="mono">${esc(e.entry_ulid)}</td><td class="mono">${esc(e.source_type)}</td><td>${esc(e.currency)}</td><td class="mono">${money(e.debit_total)}</td><td class="mono">${money(e.credit_total)}</td><td>${e.balanced?'متوازن':'اختلال'}</td></tr>`)),'القيد الأصلي لا يُحذف؛ التصحيح بقيد عكسي');
    }

    function renderFees(meta) {
        return card('الرسوم والعمولات', `<div class="row g-2 mb-3">${metric('المحصّل',money(meta.gross))}${metric('دخل المنصة',money(meta.net))}${metric('عمولة الوكلاء',money(meta.agent_commission))}${metric('فرق غير مفسر',money(meta.unexplained))}${metric('عدد عمليات الرسوم',meta.transaction_count??0)}${metric('ربح اليوم',money(meta.today_net))}${metric('ربح تراكمي',money(meta.lifetime_net))}${metric('معلق للتسوية',money(meta.pending))}</div>` + table(['النوع','الاسم','العدد','المحصّل'],(meta.by_type||[]).map(r=>`<tr><td class="mono">${esc(r.type)}</td><td>${esc(r.label)}</td><td>${r.count}</td><td class="mono">${money(r.gross)}</td></tr>`)),meta.balanced?'المعادلة متوازنة':'يوجد فرق غير مفسر يحتاج تحقيق');
    }

    function renderReconciliation(meta) {
        return card('مطابقة المحافظ بالدفتر', `<div class="row g-2">${metric('المفحوص',meta.checked??meta.total??0)}${metric('المتطابق',meta.reconciled??0)}${metric('المختلف',meta.divergent??0)}${metric('غير قابل للتحقق',meta.unverifiable??0)}</div>`,'الرصيد التشغيلي مقابل الرصيد المشتق من القيود');
    }

    function renderMerchant(meta) {
        return card('محفظة التجار', `<div class="row g-2 mb-3">${metric('إجمالي التجار',meta.total)}${metric('موثق',meta.verified)}${metric('تنتهي خلال 30 يوم',meta.expiring_30d)}${metric('منتهية',meta.expired)}</div><div class="row g-3"><div class="col-lg-3"><h6>القطاعات</h6>${breakdown(meta.by_business_type)}</div><div class="col-lg-3"><h6>الباقات</h6>${breakdown(meta.by_plan)}</div><div class="col-lg-3"><h6>التحقق</h6>${breakdown(meta.by_verification)}</div><div class="col-lg-3"><h6>المخاطر</h6>${breakdown(meta.by_risk)}</div></div>`,'من ملفات التجار الأصلية');
    }

    function renderCustomer(meta) {
        return card('نشاط العملاء', `<div class="row g-2">${metric('كل العملاء',meta.total_customers)}${metric('عملاء جدد',meta.new_customers_in_period)}${metric('عملاء نفذوا عمليات',meta.transacting_customers_in_period)}${metric('صفوف عمليات الفترة',meta.transaction_rows_in_period)}${metric('خاملون 90 يوماً',meta.dormant_90_days)}${metric('مصدر العمليات',meta.transactions_source_available?'متاح':'غير متاح')}</div>`,'الحساب على الخادم؛ لا حد 500 عملية');
    }

    function renderAgent(meta) {
        const s=meta.summary||{};
        return card('سيولة الوكلاء ومطابقة الخزن', `<div class="row g-2 mb-3">${metric('الفروع',s.branches)}${metric('النشطة',s.active)}${metric('نقد فعلي',money(s.cash_on_hand))}${metric('نقد متوقع',money(s.expected_cash))}${metric('الفرق',money(s.difference))}${metric('عهدة إلكترونية',money(s.emoney_balance))}${metric('غير متطابق',s.unreconciled)}${metric('خزنة مفقودة',s.missing_till)}</div>` + table(['الفرع','النقد','المتوقع','الفرق','العهدة الإلكترونية','الحالة'],(meta.branches||[]).map(b=>`<tr><td>${esc(b.code)} · ${esc(b.name)}</td><td class="mono">${money(b.cash_on_hand)}</td><td class="mono">${money(b.expected_cash)}</td><td class="mono">${money(b.difference)}</td><td class="mono">${money(b.emoney_balance)}</td><td>${b.reconciles?'متطابق':'تحقيق مطلوب'}</td></tr>`)),`تاريخ التقرير ${meta.date??'—'}`);
    }

    function renderSubscriptions(meta) {
        return card('الباقات والاشتراكات', `<div class="row g-2 mb-3">${metric('MRR تعاقدي',money(meta.mrr_contract_value_sar),'SAR')}${metric('ARR تعاقدي',money(meta.arr_contract_value_sar),'SAR')}${metric('نشطة',meta.total_active)}${metric('مدفوعة',meta.total_paying)}${metric('محصل في الفترة',money(meta.collected_in_period_sar),'SAR')}${metric('تنتهي خلال 7 أيام',meta.expiring_in_7_days)}${metric('تنتهي خلال 30 يوم',meta.expiring_in_30_days)}${metric('منتهية لم تعالج',meta.expired_not_processed)}</div>` + table(['الباقة','المشتركون','السعر الشهري','القيمة الشهرية'],(meta.by_plan||[]).map(p=>`<tr><td>${esc(p.label)}</td><td>${p.count}</td><td class="mono">${money(p.monthly_price_sar)} SAR</td><td class="mono">${money(p.monthly_contract_value_sar)} SAR</td></tr>`)) + `<div class="alert alert-light border mb-0 mt-3">MRR هنا قيمة تعاقدية متكررة، <strong>وليست إيراداً محاسبياً</strong>. الإيراد الرسمي يُقرأ من قائمة الدخل.</div>`,'مبالغ SAR تُحسب كسلاسل عشرية دون float');
    }

    function renderKyc(meta) {
        const c=meta.customers||{}, m=meta.merchants||{}, a=m.pending_aging||{};
        return card('KYC والتحقق', `<div class="row g-2 mb-3">${metric('العملاء',c.total)}${metric('عملاء موثقون',c.verified)}${metric('تحديث مطلوب',c.update_required)}${metric('طلبات تجار في الفترة',m.submitted_in_period)}${metric('تراكم قيد المراجعة',m.pending_backlog)}${metric('متوسط المراجعة',m.average_review_minutes??'—','دقيقة')}${metric('متأخر 8+ أيام',a['8_plus_days']??0)}${metric('4–7 أيام',a['4_7_days']??0)}</div><div class="row g-3"><div class="col-lg-6"><h6>حالات طلبات التجار</h6>${breakdown(m.status_in_period)}</div><div class="col-lg-6"><h6>مستويات KYC للعملاء</h6>${breakdown(c.by_tier)}</div></div>`,'العميل من حالته الأصلية والتاجر من طابور التحقق');
    }

    function renderAudit(meta, rbac=false) {
        const title=rbac?'تغييرات الصلاحيات والأدوار':'الإجراءات الحساسة';
        const summary=rbac ? `<div class="row g-2 mb-3">${metric('التغييرات',meta.total_changes)}</div>` : `<div class="row g-2 mb-3">${metric('كل أحداث التدقيق',meta.total_audit_events)}${metric('حساسة',meta.sensitive_events)}${metric('حرجة',meta.critical_events)}${metric('مرفوضة/محظورة',meta.denied_or_blocked)}</div>`;
        return card(title, summary + table(['الوقت','الفاعل','الموضوع','الإجراء','القرار','الخطورة'],(meta.recent||[]).map(r=>`<tr><td>${esc(r.created_at)}</td><td>${esc(r.actor_user_id??r.actor_type??'—')}</td><td>${esc(r.subject_type)} ${esc(r.subject_id??'')}</td><td class="mono">${esc(r.action)}</td><td class="mono">${esc(r.decision_code)}</td><td>${esc(r.severity)}</td></tr>`)),'السياق الحساس لا يُعرض في التقرير');
    }

    function renderSupport(meta) {
        const a=meta.backlog_aging||{};
        return card('الدعم وزمن الحل', `<div class="row g-2 mb-3">${metric('أنشئت في الفترة',meta.created_in_period)}${metric('حُلّت في الفترة',meta.resolved_in_period)}${metric('التراكم المفتوح',meta.open_backlog)}${metric('غير مسندة',meta.unassigned_backlog)}${metric('عاجلة',meta.urgent_backlog)}${metric('متوسط الحل',meta.average_resolution_minutes??'—','دقيقة')}${metric('متأخر 8+ أيام',a['8_plus_days']??0)}${metric('خرق SLA',meta.sla_breach_rate??'غير قابل للقياس')}</div><div class="row g-3"><div class="col-lg-4"><h6>الحالات</h6>${breakdown(meta.by_status)}</div><div class="col-lg-4"><h6>الأولويات</h6>${breakdown(meta.by_priority)}</div><div class="col-lg-4"><h6>الأسباب</h6>${breakdown(meta.by_category)}</div></div>`,'لا نحسب خرق SLA قبل ضبط هدف زمني رسمي');
    }

    function renderAml(meta) {
        const health=meta.health||{}, inv=meta.investigations||{}, large=meta.large_transactions||{}, rep=meta.reports||{};
        const gaps=[];
        if(meta.watchlist?.configured===false) gaps.push(meta.watchlist.why);
        if(meta.pep?.configured===false) gaps.push(meta.pep.why);
        return card('AML وSTR/CTR', `<div class="row g-2 mb-3">${metric('قواعد فعالة',health.active_rules)}${metric('قواعد ظل',health.shadow_rules)}${metric('عمليات كبيرة 30ي',large.flagged_30d)}${metric('CTR منشأة 30ي',large.ctr_generated_30d)}${metric('تحقيقات مفتوحة',inv.open)}${metric('حرجة مفتوحة',inv.critical_open)}${metric('غير مسندة',inv.unassigned)}${metric('أقدم تحقيق/ساعة',inv.oldest_open_hours)}</div>${gaps.length?`<div class="alert alert-warning mb-0"><strong>نواقص رقابية معلنة:</strong><br>${gaps.map(esc).join('<br>')}</div>`:''}`,'المؤشرات غير المبنية لا تُعرض كصفر');
    }

    function render(meta,type) {
        if (meta?.available === false) return `<div class="alert alert-warning">المصدر غير متاح: ${esc(meta.source)} (${esc(meta.reason)})</div>`;
        const handlers={
            'trial-balance':renderTrial, 'income-statement':renderIncome, 'balance-sheet':renderBalance,
            'cash-flow':renderCashFlow, 'liquidity':m=>renderLiquidity(m,false), 'safeguarded-funds':m=>renderLiquidity(m,true),
            'general-ledger':renderGeneralLedger, 'fees-commissions':renderFees, 'transaction-volume':renderVolume,
            'transaction-exceptions':renderExceptions, 'reconciliation':renderReconciliation, 'merchant-portfolio':renderMerchant,
            'customer-activity':renderCustomer, 'agent-liquidity':renderAgent, 'subscriptions':renderSubscriptions,
            'kyc-pipeline':renderKyc, 'aml-regulatory':renderAml, 'audit-sensitive-actions':m=>renderAudit(m,false),
            'rbac-changes':m=>renderAudit(m,true), 'support-operations':renderSupport,
        };
        return handlers[type] ? handlers[type](meta) : empty('لا يوجد عارض لهذا التقرير.');
    }

    async function load(type) {
        const from=document.getElementById('report-from').value;
        const to=document.getElementById('report-to').value;
        const params=new URLSearchParams();
        if (['balance-sheet','liquidity','safeguarded-funds'].includes(type)) {
            if(to) params.set('as_of',to);
        } else if (type==='agent-liquidity') {
            if(to) params.set('date',to);
        } else if (!['reconciliation','merchant-portfolio','aml-regulatory'].includes(type)) {
            if(from) params.set('from',from);
            if(to) params.set('to',to);
        }
        if(type==='general-ledger') params.set('limit','30');
        if(['transaction-exceptions','audit-sensitive-actions','rbac-changes'].includes(type)) params.set('limit','50');

        state.textContent='جاري القراءة من مصدر الحقيقة...';
        result.innerHTML='';
        document.querySelectorAll('[data-report]').forEach(b=>b.disabled=true);
        try {
            const response=await fetch(routes[type]+(params.toString()?'?'+params.toString():''),{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
            const json=await response.json();
            if(!response.ok||!json.success) throw new Error(json.message||'تعذر تحميل التقرير');
            state.textContent=`تم التحديث من الخادم · ${json.meta.generated_at??'الآن'}`;
            result.innerHTML=render(json.meta,type);
            result.scrollIntoView({behavior:'smooth',block:'start'});
        } catch(error) {
            state.textContent='تعذر تحميل التقرير.';
            result.innerHTML=`<div class="alert alert-danger shadow-sm">${esc(error?.message||'خطأ غير معروف')}</div>`;
        } finally {
            document.querySelectorAll('[data-report]').forEach(b=>b.disabled=false);
        }
    }

    document.querySelectorAll('[data-report]').forEach(button=>button.addEventListener('click',()=>load(button.dataset.report)));
})();
</script>
@endpush
