@extends('layouts.admin.app')

@section('title', 'مركز التقارير')

@push('css_or_js')
<style>
    .reporting-center .hero { background:linear-gradient(135deg,var(--amial-primary) 0%,var(--amial-primary-light) 100%); color:var(--amial-surface); border:0; }
    .reporting-center .report-toolbar { border:1px solid var(--amial-border,#e7ebf3); border-radius:16px; background:var(--amial-surface,#fff); }
    .reporting-center .currency-card { border:1px solid var(--amial-border,#e7ebf3); border-radius:14px; height:100%; }
    .reporting-center .metric { border:1px solid var(--amial-border,#e7ebf3); border-radius:12px; padding:12px; height:100%; background:var(--amial-surface,#fff); }
    .reporting-center .metric .value { font-size:1.05rem; font-weight:800; direction:ltr; text-align:right; }
    .reporting-center .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; direction:ltr; }
    .reporting-center .table td,.reporting-center .table th { vertical-align:middle; white-space:nowrap; }
    .reporting-center .report-buttons .btn { min-width:132px; }
    .reporting-center .source-note { border-inline-start:3px solid var(--amial-primary); padding-inline-start:10px; }
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
                    <p class="mb-0 opacity-75">مصدر مالي واحد: الدفتر والمحرّكات الأصلية. العملات منفصلة، ولا يوجد جمع أو تحويل صامت.</p>
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
        <strong>قاعدة المركز:</strong> التقرير الذي لا يملك مصدر حقيقة موثوقاً يبقى «ناقصاً». لا تُملأ الفجوات بأرقام الواجهة أو بالتقدير، وكل قراءة هنا تُسجّل في سجل التدقيق.
    </div>

    <div class="report-toolbar shadow-sm p-3 p-lg-4 mb-4">
        <div class="row g-3 align-items-end mb-3">
            <div class="col-md-4">
                <label class="form-label">من</label>
                <input id="report-from" type="date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">إلى / كما في</label>
                <input id="report-to" type="date" class="form-control" value="{{ now()->toDateString() }}">
            </div>
            <div class="col-md-4">
                <div class="small text-muted source-note">التقارير الزمنية تستخدم الفترة، وتقارير المركز المالي تستخدم تاريخ «كما في».</div>
            </div>
        </div>

        <div class="report-buttons d-flex flex-wrap gap-2 mb-2">
            <button class="btn btn-primary" data-report="trial-balance">ميزان المراجعة</button>
            <button class="btn btn-outline-primary" data-report="income-statement">قائمة الدخل</button>
            <button class="btn btn-outline-primary" data-report="balance-sheet">الميزانية</button>
            <button class="btn btn-outline-primary" data-report="cash-flow">التدفق النقدي</button>
            <button class="btn btn-outline-success" data-report="liquidity">مركز السيولة</button>
            <button class="btn btn-outline-success" data-report="safeguarded-funds">غطاء أموال العملاء</button>
            <button class="btn btn-outline-dark" data-report="transaction-volume">حجم المعاملات</button>
            <button class="btn btn-outline-danger" data-report="transaction-exceptions">الفاشلة/العكسية/المعلقة</button>
            <button class="btn btn-outline-secondary" data-report="reconciliation">مطابقة المحافظ</button>
        </div>

        <div id="report-state" class="text-muted small mt-3" aria-live="polite">اختر تقريراً لعرضه.</div>
    </div>

    <div id="report-result" class="mb-4"></div>

    <div class="row g-3">
        @foreach($catalog as $domain)
            <div class="col-xl-6">
                <div class="card border-0 shadow-sm h-100">
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
        'transaction-volume': @json(route('admin.amial.reporting-center.transaction-volume')),
        'transaction-exceptions': @json(route('admin.amial.reporting-center.transaction-exceptions')),
        'reconciliation': @json(route('admin.amial.reporting-center.reconciliation')),
    };
    const state = document.getElementById('report-state');
    const result = document.getElementById('report-result');
    const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    const money = value => {
        const raw = String(value ?? '0').trim();
        const negative = raw.startsWith('-');
        const clean = negative ? raw.slice(1) : raw;
        const [wholeRaw, fractionRaw = ''] = clean.split('.');
        const whole = (wholeRaw || '0').replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const fraction = fractionRaw.replace(/0+$/, '');
        return (negative ? '-' : '') + whole + (fraction ? '.' + fraction : '');
    };
    const metric = (label, value, suffix = '') => `<div class="col-6 col-lg-3"><div class="metric"><div class="small text-muted">${esc(label)}</div><div class="value">${esc(value)}${suffix ? ' ' + esc(suffix) : ''}</div></div></div>`;
    const badge = ok => ok ? '<span class="badge bg-success">سليم</span>' : '<span class="badge bg-danger">يحتاج متابعة</span>';
    const cardStart = (title, currency, subtitle = '') => `<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between gap-2"><div><h5 class="mb-1">${esc(title)}</h5>${subtitle ? `<div class="small text-muted">${esc(subtitle)}</div>` : ''}</div>${currency ? `<span class="badge bg-light text-dark mono">${esc(currency)}</span>` : ''}</div><div class="card-body px-4">`;
    const cardEnd = '</div></div>';

    function renderTrial(meta) {
        return (meta.by_currency || []).map(c => {
            const rows = (c.accounts || []).map(a => `<tr><td class="mono">${esc(a.account_code)}</td><td>${esc(a.name)}</td><td>${esc(a.account_type)}</td><td class="text-end mono">${money(a.opening_balance)}</td><td class="text-end mono">${money(a.period_debit)}</td><td class="text-end mono">${money(a.period_credit)}</td><td class="text-end mono">${money(a.closing_balance)}</td></tr>`).join('');
            return cardStart('ميزان المراجعة', c.currency, 'افتتاح الفترة + الحركة + رصيد الإقفال') +
                `<div class="row g-2 mb-3">${metric('مدين الفترة', money(c.period_debit))}${metric('دائن الفترة', money(c.period_credit))}${metric('فرق الإقفال', money(c.closing_difference))}${metric('الحالة', c.balanced ? 'متوازن' : 'غير متوازن')}</div>` +
                `<div class="table-responsive"><table class="table table-hover table-sm"><thead><tr><th>الحساب</th><th>الاسم</th><th>النوع</th><th>افتتاح</th><th>مدين الفترة</th><th>دائن الفترة</th><th>إقفال</th></tr></thead><tbody>${rows}</tbody></table></div>` + cardEnd;
        }).join('') || empty('لا توجد قيود ضمن النطاق المحدد.');
    }

    function renderIncome(meta) {
        return (meta.by_currency || []).map(c => {
            const rows = [
                ...(c.revenue_accounts || []).map(a => ({...a, group:'إيراد'})),
                ...(c.expense_accounts || []).map(a => ({...a, group:'مصروف'})),
            ].map(a => `<tr><td>${esc(a.group)}</td><td class="mono">${esc(a.account_code)}</td><td>${esc(a.name)}</td><td class="text-end mono">${money(a.balance)}</td></tr>`).join('');
            return cardStart('قائمة الدخل', c.currency) +
                `<div class="row g-2 mb-3">${metric('الإيرادات', money(c.revenue))}${metric('المصروفات', money(c.expenses))}${metric('صافي الدخل', money(c.net_income))}${metric('سلامة القيود', c.ledger_balanced ? 'متوازنة' : 'اختلال')}</div>` +
                `<div class="table-responsive"><table class="table table-hover table-sm"><thead><tr><th>الفئة</th><th>الحساب</th><th>الاسم</th><th>رصيد الفترة</th></tr></thead><tbody>${rows}</tbody></table></div>` + cardEnd;
        }).join('') || empty('لا توجد إيرادات أو مصروفات ضمن الفترة.');
    }

    function renderBalance(meta) {
        return (meta.by_currency || []).map(c => {
            const rows = [];
            Object.entries(c.accounts || {}).forEach(([group, accounts]) => (accounts || []).forEach(a => rows.push(`<tr><td>${esc(group)}</td><td class="mono">${esc(a.account_code)}</td><td>${esc(a.name)}</td><td class="text-end mono">${money(a.balance)}</td></tr>`)));
            return cardStart('الميزانية العمومية', c.currency) +
                `<div class="row g-2 mb-3">${metric('الأصول', money(c.assets))}${metric('الالتزامات', money(c.liabilities))}${metric('حقوق الملكية المعدلة', money(c.equity_with_current_earnings))}${metric('فرق المعادلة', money(c.equation_gap))}</div>` +
                `<div class="mb-2">${badge(c.balanced)} <span class="small text-muted ms-2">الأرباح غير المقفلة: <span class="mono">${money(c.current_earnings_not_closed)}</span></span></div>` +
                `<div class="table-responsive"><table class="table table-hover table-sm"><thead><tr><th>الفئة</th><th>الحساب</th><th>الاسم</th><th>الرصيد</th></tr></thead><tbody>${rows.join('')}</tbody></table></div>` + cardEnd;
        }).join('') || empty('لا توجد أرصدة دفترية حتى هذا التاريخ.');
    }

    function renderCashFlow(meta) {
        return (meta.by_currency || []).map(c => cardStart('التدفق النقدي', c.currency, `تغطية التصنيف ${c.classification_coverage_pct}%`) +
            `<div class="row g-2 mb-3">${metric('نقد افتتاحي', money(c.opening_cash))}${metric('تشغيلي', money(c.operating))}${metric('استثماري', money(c.investing))}${metric('تمويلي', money(c.financing))}</div>` +
            `<div class="row g-2">${metric('غير مصنف', money(c.unclassified))}${metric('صافي التغير', money(c.net_change))}${metric('نقد إقفال', money(c.closing_cash))}${metric('فرق التحقق', money(c.closing_difference))}</div>` +
            `<div class="small mt-3">${badge(c.reconciled_to_cash_accounts)} <span class="text-muted">التحويلات الداخلية بين حسابات النقد: ${esc(c.internal_cash_transfers)}</span></div>` + cardEnd
        ).join('') || empty('لا توجد حركة نقدية ضمن الفترة.');
    }

    function renderLiquidity(meta) {
        return (meta.by_currency || []).map(c => cardStart('مركز السيولة', c.currency) +
            `<div class="row g-2 mb-3">${metric('الأصول السائلة', money(c.liquid_assets))}${metric('أموال العملاء', money(c.customer_wallets))}${metric('أموال التجار', money(c.merchant_wallets))}${metric('أموال الوكلاء', money(c.agent_wallets))}</div>` +
            `<div class="row g-2">${metric('التزامات المحافظ الخارجية', money(c.external_wallet_obligations))}${metric('كل الالتزامات', money(c.total_liabilities))}${metric('فائض/عجز بعد المحافظ', money(c.liquid_surplus_after_external_wallets))}${metric('تغطية المحافظ الخارجية', c.external_wallet_coverage_ratio ?? '—', c.external_wallet_coverage_ratio === null ? '' : '%')}</div>` +
            `<div class="small mt-3">${badge(c.external_wallets_covered)} <span class="text-muted">لا تُحوّل العملات صامتاً؛ كل عملة مستقلة.</span></div>` + cardEnd
        ).join('') || empty('لا توجد أرصدة سيولة دفترية حتى هذا التاريخ.');
    }

    function renderSafeguard(meta) {
        return (meta.by_currency || []).map(c => cardStart('غطاء أموال العملاء', c.currency) +
            `<div class="row g-2 mb-3">${metric('أموال العملاء', money(c.customer_funds))}${metric('الغطاء السائل', money(c.liquid_cover))}${metric('فائض/عجز العميل', money(c.customer_surplus_or_shortfall))}${metric('نسبة التغطية', c.customer_coverage_ratio ?? '—', c.customer_coverage_ratio === null ? '' : '%')}</div>` +
            `<div class="row g-2">${metric('أموال التجار', money(c.merchant_funds))}${metric('أموال الوكلاء', money(c.agent_funds))}${metric('فائض/عجز كل المحافظ الخارجية', money(c.external_wallet_surplus_or_shortfall))}${metric('تغطية كل المحافظ', c.external_wallet_coverage_ratio ?? '—', c.external_wallet_coverage_ratio === null ? '' : '%')}</div>` +
            `<div class="small mt-3">${badge(c.customer_status === 'covered')} <span class="text-muted">حالة غطاء أموال العملاء: ${esc(c.customer_status)}</span></div>` + cardEnd
        ).join('') || empty('لا توجد التزامات عملاء دفترية حتى هذا التاريخ.');
    }

    function renderVolume(meta) {
        return (meta.by_currency || []).map(c => {
            const rows = (c.by_source || []).map(r => `<tr><td class="mono">${esc(r.source_type)}</td><td>${esc(r.entries)}</td><td class="text-end mono">${money(r.volume)}</td><td>${r.is_reversal ? '<span class="badge bg-warning text-dark">عكسي</span>' : '<span class="badge bg-success">أصلي</span>'}</td></tr>`).join('');
            return cardStart('حجم المعاملات الدفتري', c.currency, 'القيد المحاسبي حدث واحد؛ العكس معروض منفصلاً') +
                `<div class="row g-2 mb-3">${metric('الأحداث الأصلية', c.original_entries)}${metric('الأحداث العكسية', c.reversal_entries)}${metric('الحجم الإجمالي', money(c.gross_original_volume))}${metric('الصافي بعد العكس', money(c.net_after_reversals))}</div>` +
                `<div class="table-responsive"><table class="table table-hover table-sm"><thead><tr><th>نوع المصدر</th><th>العدد</th><th>الحجم</th><th>النوع</th></tr></thead><tbody>${rows}</tbody></table></div>` + cardEnd;
        }).join('') || empty('لا توجد أحداث دفترية ضمن الفترة.');
    }

    function renderExceptions(meta) {
        const p = meta.pending_transfers || {};
        const r = meta.reversals || {};
        const j = meta.rejections || {};
        const pendingRows = (p.summary || []).map(x => `<tr><td>${esc(x.status)}</td><td>${esc(x.count)}</td><td class="text-end mono">${money(x.amount)}</td></tr>`).join('');
        const rejectionRows = (j.by_code || []).map(x => `<tr><td class="mono">${esc(x.decision_code)}</td><td>${esc(x.count)}</td></tr>`).join('');
        const reversalRows = (r.by_currency || []).map(x => `<tr><td class="mono">${esc(x.currency)}</td><td>${esc(x.count)}</td><td class="text-end mono">${money(x.amount)}</td></tr>`).join('');
        return cardStart('الاستثناءات المالية', '', 'المعلقة والفاشلة والعكسية من مصادر حالتها الأصلية') +
            `<div class="row g-2 mb-4">${metric('معلقة متأخرة', p.overdue_holding ?? 0)}${metric('قرارات مرفوضة/فاشلة', j.count ?? 0)}${metric('عملات بها عكس', (r.by_currency || []).length)}${metric('الفترة', `${meta.from ?? '—'} → ${meta.to ?? '—'}`)}</div>` +
            `<div class="row g-3"><div class="col-lg-4"><h6>التحويلات المعلقة</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>الحالة</th><th>العدد</th><th>المبلغ</th></tr></thead><tbody>${pendingRows}</tbody></table></div></div>` +
            `<div class="col-lg-4"><h6>أسباب الرفض</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>القرار</th><th>العدد</th></tr></thead><tbody>${rejectionRows}</tbody></table></div></div>` +
            `<div class="col-lg-4"><h6>القيود العكسية</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>العملة</th><th>العدد</th><th>المبلغ</th></tr></thead><tbody>${reversalRows}</tbody></table></div></div></div>` + cardEnd;
    }

    function empty(message) {
        return `<div class="alert alert-light border shadow-sm">${esc(message)}</div>`;
    }

    function renderReconciliation(meta) {
        const checked = meta.checked ?? meta.total ?? 0;
        return cardStart('مطابقة المحافظ بالدفتر', '', 'الرصيد التشغيلي مقابل الرصيد المشتق من القيود') +
            `<div class="row g-2">${metric('المفحوص', checked)}${metric('المتطابق', meta.reconciled ?? 0)}${metric('المختلف', meta.divergent ?? 0)}${metric('غير قابل للتحقق', meta.unverifiable ?? 0)}</div>` + cardEnd;
    }

    function render(meta, type) {
        if (type === 'trial-balance') return renderTrial(meta);
        if (type === 'income-statement') return renderIncome(meta);
        if (type === 'balance-sheet') return renderBalance(meta);
        if (type === 'cash-flow') return renderCashFlow(meta);
        if (type === 'liquidity') return renderLiquidity(meta);
        if (type === 'safeguarded-funds') return renderSafeguard(meta);
        if (type === 'transaction-volume') return renderVolume(meta);
        if (type === 'transaction-exceptions') return renderExceptions(meta);
        if (type === 'reconciliation') return renderReconciliation(meta);
        return empty('لا يوجد عارض لهذا التقرير.');
    }

    async function load(type) {
        const from = document.getElementById('report-from').value;
        const to = document.getElementById('report-to').value;
        const params = new URLSearchParams();
        if (['balance-sheet','liquidity','safeguarded-funds'].includes(type)) {
            if (to) params.set('as_of', to);
        } else if (type !== 'reconciliation') {
            if (from) params.set('from', from);
            if (to) params.set('to', to);
        }

        state.textContent = 'جاري القراءة من مصدر الحقيقة...';
        result.innerHTML = '';
        document.querySelectorAll('[data-report]').forEach(b => b.disabled = true);
        try {
            const response = await fetch(routes[type] + (params.toString() ? '?' + params.toString() : ''), {
                headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
                credentials: 'same-origin',
            });
            const json = await response.json();
            if (!response.ok || !json.success) throw new Error(json.message || 'تعذر تحميل التقرير');
            state.textContent = `تم التحديث من الخادم · ${json.meta.generated_at ?? 'الآن'}`;
            result.innerHTML = render(json.meta, type);
        } catch (error) {
            state.textContent = 'تعذر تحميل التقرير.';
            result.innerHTML = `<div class="alert alert-danger shadow-sm">${esc(error?.message || 'خطأ غير معروف')}</div>`;
        } finally {
            document.querySelectorAll('[data-report]').forEach(b => b.disabled = false);
        }
    }

    document.querySelectorAll('[data-report]').forEach(button => button.addEventListener('click', () => load(button.dataset.report)));
})();
</script>
@endpush
