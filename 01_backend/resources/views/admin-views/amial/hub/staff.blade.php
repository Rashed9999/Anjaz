@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — لوحة الموظفين (طاقم نقاط بيع التجّار).
     يعرض موظفي نقاط البيع (PosUser) بأدوارهم وفروعهم، مع تفعيل/تعطيل حقيقي
     (الموظف المعطَّل لا يستطيع الدخول لنقطة البيع). --}}

@section('title', 'لوحة الموظفين')

@section('content')
<div class="content container-fluid">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">لوحة الموظفين — نقاط البيع</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">إجمالي الموظفين</small>
            <div class="fs-3 fw-bold" id="s-total">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">النشطون</small>
            <div class="fs-3 fw-bold text-success" id="s-active">…</div>
        </div></div>
    </div>

    <div class="card stat-card">
        <div class="card-header d-flex gap-2 align-items-center">
            <input type="text" id="search-box" class="form-control" style="max-width:280px" placeholder="بحث بالاسم أو رقم نقطة البيع أو الهاتف…">
            <span class="text-muted small ms-auto" id="page-info"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الموظف</th><th>رقم النقطة</th><th>التاجر</th><th>الفرع</th>
                    <th>الأدوار</th><th>آخر دخول</th><th>الحالة</th><th></th>
                </tr></thead>
                <tbody id="tbody"><tr><td colspan="8" class="text-center text-muted py-4">جارٍ التحميل…</td></tr></tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <div class="btn-group">
                <button class="btn btn-sm btn-outline-secondary" id="prev-page">السابق</button>
                <button class="btn btn-sm btn-outline-secondary" id="next-page">التالي</button>
            </div>
        </div>
    </div>
    <p class="text-muted small mt-2">إضافة موظف جديد تتم من تطبيق التاجر نفسه (إدارة نقاط البيع). هذه اللوحة للإشراف والتفعيل/التعطيل على مستوى المنصّة.</p>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let page = 1, lastPage = 1, search = '';

    async function post(url, body) {
        const r = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: JSON.stringify(body || {})});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    async function load() {
        const tbody = document.getElementById('tbody');
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        // AMIAL-MERCHANT-360-DRILL-001 — `?merchant=` من ملفّ التاجر ٣٦٠.
        // والنقطةُ تقبل `merchant_id` منذ بُنيت — ولا شيءَ كان يمرّره.
        const mid = new URLSearchParams(location.search).get('merchant');
        const r = await fetch(`${base}/staff/list.json?page=${page}&search=${encodeURIComponent(search)}`
            + (mid ? `&merchant_id=${encodeURIComponent(mid)}` : ''),
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        lastPage = j.last_page;
        document.getElementById('page-info').textContent = `${j.total} موظف — صفحة ${j.current_page} من ${j.last_page}`;
        document.getElementById('s-total').textContent = j.summary?.total ?? '—';
        document.getElementById('s-active').textContent = j.summary?.active ?? '—';

        tbody.innerHTML = j.data.length ? j.data.map(p => `
            <tr>
                <td>${esc(p.display_name ?? '—')}<div class="small text-muted" dir="ltr">${esc(p.phone)}</div></td>
                <td class="font-monospace">${esc(p.pos_number ?? '—')}</td>
                <td>${esc(p.merchant)}</td>
                <td>${esc(p.branch ?? '—')}</td>
                <td>${(p.roles || []).map(r => `<span class="badge bg-info me-1">${esc(r)}</span>`).join('') || '<span class="text-muted small">—</span>'}</td>
                <td class="small">${esc(p.last_login ?? 'لم يدخل')}</td>
                <td>${p.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-danger">معطَّل</span>'}</td>
                <td><button class="btn btn-sm ${p.is_active ? 'btn-outline-danger' : 'btn-outline-success'}" data-id="${p.id}">${p.is_active ? 'تعطيل' : 'تفعيل'}</button></td>
            </tr>`).join('')
            : '<tr><td colspan="8" class="text-center text-muted py-4">لا موظفين مسجّلين بعد</td></tr>';
    }

    document.getElementById('tbody').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-id]');
        if (!btn) return;
        try { const j = await post(`${base}/staff/${btn.dataset.id}/toggle-active`, {}); alert(j.message); load(); }
        catch (err) { alert(err.message); }
    });

    let t;
    document.getElementById('search-box').addEventListener('input', (e) => { clearTimeout(t); t = setTimeout(() => { search = e.target.value.trim(); page = 1; load(); }, 350); });
    document.getElementById('prev-page').addEventListener('click', () => { if (page > 1) { page--; load(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; load(); } });
    load();
})();
</script>
@endpush
