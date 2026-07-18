@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — لوحة الاشتراكات (الباقات).
     حقيقية بالكامل: تغيير الباقة/التمديد يمرّ عبر SubscriptionService نفسه الذي
     يستخدمه التطبيق (سجلّ تغييرات + فتح/غلق الميزات فوراً في تطبيق التاجر). --}}

@section('title', 'لوحة الاشتراكات')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">لوحة الاشتراكات</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>

    {{-- ملخّص --}}
    <div class="row g-3 mb-4" id="summary-cards">
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">تجّار مشتركون</small>
            <div class="fs-3 fw-bold" id="sum-total">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">اشتراكات نشطة</small>
            <div class="fs-3 fw-bold text-success" id="sum-active">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">منتهية</small>
            <div class="fs-3 fw-bold text-danger" id="sum-expired">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">تنتهي خلال 7 أيام</small>
            <div class="fs-3 fw-bold text-warning" id="sum-expiring">…</div>
        </div></div>
    </div>

    <div class="card stat-card">
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <input type="text" id="search-box" class="form-control" style="max-width:260px" placeholder="بحث باسم التاجر أو هاتفه…">
            <select id="plan-filter" class="form-select" style="max-width:200px">
                <option value="">كل الباقات</option>
                @foreach($plans as $p)
                    <option value="{{ $p['code'] }}">{{ $p['label'] }} ({{ $p['code'] }})</option>
                @endforeach
            </select>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>التاجر</th><th>الهاتف</th><th>النشاط</th><th>الباقة</th>
                    <th>تنتهي في</th><th>إجراءات</th>
                </tr></thead>
                <tbody id="subs-tbody">
                    <tr><td colspan="6" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="text-muted small" id="page-info"></span>
            <div class="btn-group">
                <button class="btn btn-sm btn-outline-secondary" id="prev-page">السابق</button>
                <button class="btn btn-sm btn-outline-secondary" id="next-page">التالي</button>
            </div>
        </div>
    </div>
</div>

{{-- Modal: تغيير الباقة / تمديد --}}
<div class="modal fade" id="modal-plan" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="plan-title">إدارة الاشتراك</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">الباقة الجديدة</label>
                <select class="form-select" id="plan-select">
                    @foreach($plans as $p)
                        <option value="{{ $p['code'] }}">{{ $p['label'] }} ({{ $p['code'] }})</option>
                    @endforeach
                </select>
                <button class="btn btn-primary w-100 mt-2" id="plan-submit">تغيير الباقة</button>
            </div>
            <hr>
            <div class="mb-2">
                <label class="form-label">أو تمديد الاشتراك الحالي (أيام)</label>
                <div class="d-flex gap-2">
                    <input type="number" min="1" max="365" value="30" class="form-control" id="extend-days" dir="ltr" style="max-width:120px">
                    <button class="btn btn-outline-primary flex-fill" id="extend-submit">تمديد</button>
                </div>
            </div>
            <div class="text-danger small" id="plan-error"></div>
        </div>
    </div></div>
</div>
@endsection

@push('script')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let page = 1, lastPage = 1, search = '', plan = '', currentMerchant = null;

    async function post(url, body) {
        const r = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    async function load() {
        const tbody = document.getElementById('subs-tbody');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        const r = await fetch(`${base}/subscriptions/list.json?page=${page}&search=${encodeURIComponent(search)}&plan=${encodeURIComponent(plan)}`,
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        lastPage = j.last_page;
        document.getElementById('page-info').textContent = `صفحة ${j.current_page} من ${j.last_page} — ${j.total} تاجر`;

        const s = j.summary || {};
        document.getElementById('sum-total').textContent = j.total ?? '—';
        document.getElementById('sum-active').textContent = s.total_active ?? '—';
        document.getElementById('sum-expired').textContent = s.expired_not_processed ?? '—';
        document.getElementById('sum-expiring').textContent = s.expiring_in_7_days ?? '—';

        tbody.innerHTML = j.data.length ? j.data.map(m => `
            <tr>
                <td>${esc(m.name)}</td>
                <td dir="ltr">${esc(m.phone)}</td>
                <td>${esc(m.business_type ?? '—')}</td>
                <td><span class="badge bg-primary">${esc(m.plan_label)}</span></td>
                <td>${m.expires_at
                        ? `<span class="${m.expired ? 'text-danger fw-bold' : ''}">${esc(m.expires_at)}${m.expired ? ' (منتهٍ)' : ''}</span>`
                        : '—'}</td>
                <td><button class="btn btn-sm btn-outline-primary" data-id="${m.merchant_user_id}" data-name="${esc(m.name)}">
                    إدارة الاشتراك</button></td>
            </tr>`).join('')
            : '<tr><td colspan="6" class="text-center text-muted py-4">لا نتائج</td></tr>';
    }

    document.getElementById('subs-tbody').addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-id]');
        if (!btn) return;
        currentMerchant = btn.dataset.id;
        document.getElementById('plan-title').textContent = `إدارة اشتراك ${btn.dataset.name}`;
        document.getElementById('plan-error').textContent = '';
        new bootstrap.Modal('#modal-plan').show();
    });

    document.getElementById('plan-submit').addEventListener('click', async () => {
        const errEl = document.getElementById('plan-error'); errEl.textContent = '';
        try {
            const j = await post(`${base}/subscriptions/${currentMerchant}/plan`,
                {plan: document.getElementById('plan-select').value});
            bootstrap.Modal.getInstance(document.getElementById('modal-plan')).hide();
            alert(`${j.message} — صالحة حتى ${j.new_expires_at ?? '—'}`); load();
        } catch (err) { errEl.textContent = err.message; }
    });

    document.getElementById('extend-submit').addEventListener('click', async () => {
        const errEl = document.getElementById('plan-error'); errEl.textContent = '';
        try {
            const j = await post(`${base}/subscriptions/${currentMerchant}/extend`,
                {days: Number(document.getElementById('extend-days').value)});
            bootstrap.Modal.getInstance(document.getElementById('modal-plan')).hide();
            alert(`${j.message} — حتى ${j.new_expires_at ?? '—'}`); load();
        } catch (err) { errEl.textContent = err.message; }
    });

    let t;
    document.getElementById('search-box').addEventListener('input', (e) => {
        clearTimeout(t); t = setTimeout(() => { search = e.target.value.trim(); page = 1; load(); }, 350);
    });
    document.getElementById('plan-filter').addEventListener('change', (e) => { plan = e.target.value; page = 1; load(); });
    document.getElementById('prev-page').addEventListener('click', () => { if (page > 1) { page--; load(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; load(); } });

    load();
})();
</script>
@endpush
