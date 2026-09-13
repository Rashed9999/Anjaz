@extends('layouts.admin.app')

@section('title', 'مركز التقارير')

@section('content')
<div class="container-fluid px-0">
    <div class="card border-0 shadow-sm mb-4 bg-primary text-white">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                <div>
                    <div class="small opacity-75 mb-1">AMIAL REPORTING CENTER</div>
                    <h2 class="mb-2">📊 مركز التقارير المؤسسية</h2>
                    <p class="mb-0 opacity-75">الأرقام المالية من الدفتر ومصادر الحقيقة القائمة؛ لا حسابات موازية داخل لوحة الإدارة.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge bg-light text-dark p-2">جاهز {{ $summary['ready'] }}</span>
                    <span class="badge bg-warning text-dark p-2">جزئي {{ $summary['partial'] }}</span>
                    <span class="badge bg-danger p-2">ناقص {{ $summary['missing'] }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info shadow-sm border-0">
        <strong>قاعدة المركز:</strong> كل رقم مالي قابل للتتبع إلى المصدر. التقرير الذي لا يملك مصدر حقيقة موثوقاً يبقى «ناقص» ولا يُعوّض بتقدير أو رقم واجهة.
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4">
            <h5 class="mb-1">القلب المالي — المرحلة P0</h5>
            <div class="text-muted small">ميزان المراجعة، قائمة الدخل، الميزانية، ومطابقة المحافظ.</div>
        </div>
        <div class="card-body px-4">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">من</label>
                    <input id="report-from" type="date" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">إلى / كما في</label>
                    <input id="report-to" type="date" class="form-control" value="{{ now()->toDateString() }}">
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2 flex-wrap">
                    <button class="btn btn-primary" data-report="trial-balance">ميزان المراجعة</button>
                    <button class="btn btn-outline-primary" data-report="income-statement">قائمة الدخل</button>
                    <button class="btn btn-outline-primary" data-report="balance-sheet">الميزانية</button>
                    <button class="btn btn-outline-secondary" data-report="reconciliation">المصالحة</button>
                </div>
            </div>

            <div id="report-state" class="text-muted small mb-2">اختر تقريراً لعرضه.</div>
            <div id="report-result" class="table-responsive"></div>
        </div>
    </div>

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

<script>
(function () {
    const routes = {
        'trial-balance': @json(route('admin.amial.reporting-center.trial-balance')),
        'income-statement': @json(route('admin.amial.reporting-center.income-statement')),
        'balance-sheet': @json(route('admin.amial.reporting-center.balance-sheet')),
        'reconciliation': @json(route('admin.amial.reporting-center.reconciliation')),
    };
    const state = document.getElementById('report-state');
    const result = document.getElementById('report-result');
    const money = value => Number(value || 0).toLocaleString('ar-SA', {minimumFractionDigits: 2, maximumFractionDigits: 4});
    const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));

    function summaryCards(meta, type) {
        let items = [];
        if (type === 'trial-balance') items = [['إجمالي المدين', meta.total_debit], ['إجمالي الدائن', meta.total_credit], ['الفرق', meta.difference], ['حسابات منحرفة', meta.drifted_accounts]];
        if (type === 'income-statement') items = [['الإيرادات', meta.revenue], ['المصروفات', meta.expenses], ['صافي الدخل', meta.net_income], ['الدفتر متوازن', meta.ledger_balanced ? 'نعم' : 'لا']];
        if (type === 'balance-sheet') items = [['الأصول', meta.assets], ['الالتزامات', meta.liabilities], ['حقوق الملكية المعدلة', meta.equity_with_current_earnings], ['فرق المعادلة', meta.equation_gap]];
        if (type === 'reconciliation') items = [['المفحوص', meta.checked ?? meta.total ?? 0], ['المتطابق', meta.reconciled ?? 0], ['المختلف', meta.divergent ?? 0], ['غير قابل للتحقق', meta.unverifiable ?? 0]];
        return '<div class="row g-2 mb-3">' + items.map(i => '<div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="small text-muted">'+esc(i[0])+'</div><div class="fw-bold fs-5">'+(typeof i[1] === 'string' && /^-?\d/.test(i[1]) ? money(i[1]) : esc(i[1]))+'</div></div></div>').join('') + '</div>';
    }

    function details(meta, type) {
        if (type === 'trial-balance') {
            const rows = (meta.accounts || []).map(a => '<tr><td>'+esc(a.account_code)+'</td><td>'+esc(a.name)+'</td><td>'+esc(a.account_type)+'</td><td class="text-end">'+money(a.debit_total)+'</td><td class="text-end">'+money(a.credit_total)+'</td><td class="text-end">'+money(a.computed_balance)+'</td><td>'+(a.has_drift ? '<span class="badge bg-danger">منحرف</span>' : '<span class="badge bg-success">سليم</span>')+'</td></tr>').join('');
            return '<table class="table table-hover"><thead><tr><th>الحساب</th><th>الاسم</th><th>النوع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>الحالة</th></tr></thead><tbody>'+rows+'</tbody></table>';
        }
        if (type === 'income-statement') {
            const all = [...(meta.revenue_accounts || []).map(a => ({...a, group:'إيراد'})), ...(meta.expense_accounts || []).map(a => ({...a, group:'مصروف'}))];
            return '<table class="table table-hover"><thead><tr><th>الفئة</th><th>الحساب</th><th>الاسم</th><th>الرصيد</th></tr></thead><tbody>'+all.map(a => '<tr><td>'+esc(a.group)+'</td><td>'+esc(a.account_code)+'</td><td>'+esc(a.name)+'</td><td class="text-end">'+money(a.balance)+'</td></tr>').join('')+'</tbody></table>';
        }
        if (type === 'balance-sheet') {
            const rows = [];
            Object.entries(meta.accounts || {}).forEach(([group, accounts]) => (accounts || []).forEach(a => rows.push('<tr><td>'+esc(group)+'</td><td>'+esc(a.account_code)+'</td><td>'+esc(a.name)+'</td><td class="text-end">'+money(a.balance)+'</td></tr>')));
            return '<table class="table table-hover"><thead><tr><th>الفئة</th><th>الحساب</th><th>الاسم</th><th>الرصيد</th></tr></thead><tbody>'+rows.join('')+'</tbody></table>';
        }
        return '<pre class="bg-light border rounded p-3 text-start" dir="ltr">'+esc(JSON.stringify(meta, null, 2))+'</pre>';
    }

    async function load(type) {
        const from = document.getElementById('report-from').value;
        const to = document.getElementById('report-to').value;
        const params = new URLSearchParams();
        if (type === 'balance-sheet') { if (to) params.set('as_of', to); }
        else if (type !== 'reconciliation') { if (from) params.set('from', from); if (to) params.set('to', to); }
        state.textContent = 'جاري القراءة من مصدر الحقيقة...';
        result.innerHTML = '';
        try {
            const response = await fetch(routes[type] + (params.toString() ? '?' + params.toString() : ''), {headers:{'Accept':'application/json'}});
            const json = await response.json();
            if (!response.ok || !json.success) throw new Error(json.message || 'تعذر تحميل التقرير');
            state.textContent = 'تم التحديث الآن من الخادم.';
            result.innerHTML = summaryCards(json.meta, type) + details(json.meta, type);
        } catch (e) {
            state.textContent = 'تعذر تحميل التقرير: ' + e.message;
        }
    }

    document.querySelectorAll('[data-report]').forEach(btn => btn.addEventListener('click', () => load(btn.dataset.report)));
})();
</script>
@endsection
