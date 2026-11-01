@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — المركز المالي:
     تعبئة محفظة الإدارة + أرصدة إجمالية + بثّ حيّ لحركة كل ريال
     (من دفتر القيود المزدوج: من أين أتى وإلى أين ذهب). --}}

@section('title', 'المركز المالي')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">المركز المالي</h2>
        <span class="badge bg-success" id="live-dot">● بثّ حيّ</span>
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>

    {{-- أرصدة (تُحدَّث كل 10 ثوانٍ) --}}
    <div class="row g-3 mb-3">
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">محفظة الإدارة</small>
            <div class="fs-4 fw-bold" id="s-admin">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">أرباح الرسوم المحصّلة</small>
            <div class="fs-4 fw-bold text-success" id="s-charge">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">حجم اليوم / عدد القيود</small>
            <div class="fs-4 fw-bold" id="s-today">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">إجمالي المحجوز (كل المحافظ)</small>
            <div class="fs-4 fw-bold text-warning" id="s-held">…</div>
        </div></div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card stat-card p-3">
            <small class="text-muted">أرصدة العملاء</small>
            <div class="fs-5 fw-bold" id="s-customers">…</div>
        </div></div>
        <div class="col-md-4"><div class="card stat-card p-3">
            <small class="text-muted">أرصدة الوكلاء</small>
            <div class="fs-5 fw-bold" id="s-agents">…</div>
        </div></div>
        <div class="col-md-4"><div class="card stat-card p-3">
            <small class="text-muted">أرصدة التجّار</small>
            <div class="fs-5 fw-bold" id="s-merchants">…</div>
        </div></div>
    </div>

    {{-- تعبئة محفظة الإدارة --}}
    <div class="card stat-card mb-4">
        <div class="card-header fw-bold">إصدار رصيد الخزينة</div>
        <div class="card-body d-flex gap-2 flex-wrap align-items-end">
            <div>
                <label class="form-label">المبلغ (ر.ي)</label>
                <input type="number" min="1" class="form-control" id="topup-amount" dir="ltr" style="max-width:220px">
            </div>
            <div>
                <label class="form-label">مرجع الإثبات</label>
                <input type="text" class="form-control" id="topup-reference" maxlength="120" placeholder="TREASURY-2026-0001">
            </div>
            <div class="flex-grow-1" style="min-width:220px">
                <label class="form-label">سبب الإصدار</label>
                <input type="text" class="form-control" id="topup-reason" maxlength="500" placeholder="توريد نقد موثق إلى الخزينة">
            </div>
            <button class="btn btn-primary" id="topup-submit">ترحيل القيد</button>
            <span class="text-danger small" id="topup-error"></span>
            <span class="text-success small" id="topup-ok"></span>
        </div>
    </div>

    {{-- البثّ الحيّ لحركة المال --}}
    <div class="card stat-card">
        <div class="card-header d-flex align-items-center">
            <span class="fw-bold">حركة المال — كل ريال: من أين أتى وإلى أين ذهب</span>
            <span class="text-muted small ms-auto" id="feed-info">يُحدَّث كل 5 ثوانٍ</span>
        </div>
        <div class="table-responsive" style="max-height:520px; overflow-y:auto">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light" style="position:sticky;top:0"><tr>
                    <th>الوقت</th><th>العملية</th><th>الوصف</th>
                    <th>من (مدين)</th><th>إلى (دائن)</th><th>المبلغ</th><th>الحالة</th>
                </tr></thead>
                <tbody id="feed-tbody">
                    <tr><td colspan="7" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    // ===== الإحصاءات =====
    async function loadStats() {
        try {
            const r = await fetch(`${base}/finance/stats.json`, {headers: {'Accept': 'application/json'}});
            const j = await r.json();
            document.getElementById('s-admin').textContent = fmt(j.admin_balance) + ' ر.ي';
            document.getElementById('s-charge').textContent = fmt(j.admin_charge_earned) + ' ر.ي';
            document.getElementById('s-today').textContent = `${fmt(j.today_volume)} ر.ي / ${j.today_entries}`;
            document.getElementById('s-held').textContent = fmt(j.held_total) + ' ر.ي';
            document.getElementById('s-customers').textContent = fmt(j.customers_balance) + ' ر.ي';
            document.getElementById('s-agents').textContent = fmt(j.agents_balance) + ' ر.ي';
            document.getElementById('s-merchants').textContent = fmt(j.merchants_balance) + ' ر.ي';
        } catch (e) { /* الشبكة — المحاولة التالية بعد 10 ثوانٍ */ }
    }

    // ===== البثّ الحيّ =====
    let maxId = 0, firstLoad = true;
    const accountsCell = (arr) => (arr || []).map(a =>
        `<div class="text-nowrap"><span class="badge bg-light text-dark border">${esc(a.account)}</span>
         <span class="small text-muted">${fmt(a.amount)}</span></div>`).join('') || '—';

    function rowHtml(e, isNew) {
        return `<tr class="${isNew ? 'table-warning' : ''}" data-entry="${e.id}">
            <td class="small text-nowrap">${esc(e.posted_at ?? '')}</td>
            <td><span class="badge bg-primary">${esc(e.source_type)}</span></td>
            <td class="small">${esc(e.description ?? '')}</td>
            <td>${accountsCell(e.from)}</td>
            <td>${accountsCell(e.to)}</td>
            <td class="fw-bold text-nowrap">${fmt(e.amount)} ر.ي</td>
            <td>${e.status === 'posted' ? '<span class="badge bg-success">مُرحَّل</span>'
                                        : '<span class="badge bg-danger">معكوس</span>'}</td>
        </tr>`;
    }

    async function loadFeed() {
        try {
            const r = await fetch(`${base}/finance/feed.json?since_id=${maxId}`, {headers: {'Accept': 'application/json'}});
            const j = await r.json();
            const tbody = document.getElementById('feed-tbody');

            if (firstLoad) {
                tbody.innerHTML = j.data.length
                    ? j.data.map(e => rowHtml(e, false)).join('')
                    : '<tr><td colspan="7" class="text-center text-muted py-4">لا حركة بعد — أول عملية ستظهر هنا فور حدوثها</td></tr>';
                firstLoad = false;
            } else if (j.data.length) {
                if (tbody.querySelector('td[colspan]')) tbody.innerHTML = '';
                // الأحدث أولاً — تُدرَج أعلى الجدول مظلَّلة
                tbody.insertAdjacentHTML('afterbegin', j.data.map(e => rowHtml(e, true)).join(''));
                setTimeout(() => tbody.querySelectorAll('tr.table-warning')
                    .forEach(tr => tr.classList.remove('table-warning')), 3000);
                // حدّ أقصى 200 صف في الصفحة
                while (tbody.rows.length > 200) tbody.deleteRow(-1);
            }
            if (j.max_id > maxId) maxId = j.max_id;
            document.getElementById('live-dot').classList.replace('bg-danger', 'bg-success');
        } catch (e) {
            document.getElementById('live-dot').classList.replace('bg-success', 'bg-danger');
        }
    }

    // ===== التعبئة =====
    document.getElementById('topup-submit').addEventListener('click', async () => {
        const errEl = document.getElementById('topup-error');
        const okEl = document.getElementById('topup-ok');
        errEl.textContent = ''; okEl.textContent = '';
        try {
            const r = await fetch(`${base}/finance/topup`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                body: JSON.stringify({
                    amount: document.getElementById('topup-amount').value,
                    reference: document.getElementById('topup-reference').value,
                    reason: document.getElementById('topup-reason').value,
                }),
            });
            const j = await r.json();
            if (!r.ok) throw new Error(j.message || 'فشلت التعبئة');
            okEl.textContent = `${j.message} — الرصيد الآن: ${fmt(j.balance)} ر.ي`;
            document.getElementById('topup-amount').value = '';
            document.getElementById('topup-reference').value = '';
            document.getElementById('topup-reason').value = '';
            loadStats(); loadFeed();
        } catch (err) { errEl.textContent = err.message; }
    });

    loadStats(); loadFeed();
    setInterval(loadFeed, 5000);
    setInterval(loadStats, 10000);
})();
</script>
@endpush
