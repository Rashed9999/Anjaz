@extends('layouts.admin.app')

{{-- AMIAL-VERIFY-HUB — لوحة التحقق.
     كل حساب يسجَّل ذاتياً من التطبيق (عميل/تاجر/وكيل) يصل هنا «قيد التحقق»
     بوثائقه وبياناته، والأدمن يعتمد (يوثّق الحساب وملف التاجر) أو يرفض أو يحظر.
     القرارات كلها حقيقية: تفتح/تغلق ميزات التطبيق فوراً. --}}

@section('title', 'لوحة التحقق')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">لوحة التحقق</h2>
        <span class="badge bg-warning text-dark">{{ number_format($pendingCount) }} بانتظار المراجعة</span>
        <span class="badge badge-soft-info ms-auto">AMIAL-VERIFY-HUB</span>
    </div>

    <div class="d-flex gap-2 mb-3">
        <select id="filter" class="form-select" style="max-width:240px">
            <option value="pending">قيد التحقق (الجديدة)</option>
            <option value="rejected">المرفوضة</option>
            <option value="all">الكل</option>
        </select>
        <span class="text-muted small ms-auto align-self-center" id="page-info"></span>
        <div class="btn-group">
            <button class="btn btn-sm btn-outline-secondary" id="prev-page">السابق</button>
            <button class="btn btn-sm btn-outline-secondary" id="next-page">التالي</button>
        </div>
    </div>

    <div class="row g-3" id="cards">
        <div class="col-12 text-muted">جارٍ التحميل…</div>
    </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let page = 1, lastPage = 1, filter = 'pending';

    const roleBadge = (r, t) => ({1: 'bg-info', 3: 'bg-primary'}[t] || 'bg-secondary');

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

    function card(u) {
        const info = [
            u.id_type ? `الهوية: ${esc(u.id_type)} ${esc(u.id_number ?? '')}` : null,
            u.address ? `العنوان: ${esc(u.address)}` : null,
            u.kin ? `قريب: ${esc(u.kin)}` : null,
            u.store_name ? `المتجر: ${esc(u.store_name)} (${esc(u.business_type ?? '')})` : null,
            u.merchant_number ? `رقم التاجر: ${esc(u.merchant_number)}` : null,
            u.agent_number ? `رقم الوكيل: ${esc(u.agent_number)}` : null,
            `سُجّل: ${esc(u.registered_at ?? '')}`,
        ].filter(Boolean).map(l => `<div class="small text-muted">${l}</div>`).join('');

        const docs = (u.documents || []).map(d =>
            `<a href="${esc(d)}" target="_blank"><img src="${esc(d)}" style="height:64px;border-radius:6px;border:1px solid #ddd"></a>`
        ).join(' ') || '<span class="text-muted small">لا وثائق مرفوعة</span>';

        const state = u.kyc === 1 ? '<span class="badge bg-success">موثّق</span>'
            : (u.kyc === 2 ? '<span class="badge bg-danger">مرفوض</span>'
            : '<span class="badge bg-warning text-dark">قيد التحقق</span>');

        return `<div class="col-md-6 col-lg-4"><div class="card stat-card h-100">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <span class="badge ${roleBadge(u.role, u.type)}">${esc(u.role)}</span>
                        <strong class="ms-1">${esc(u.name)}</strong>
                    </div>
                    ${state}
                </div>
                <div class="small text-muted mb-1" dir="ltr">${esc(u.phone)}</div>
                ${info}
                <div class="d-flex gap-2 flex-wrap my-2">${docs}</div>
                <div class="mt-auto d-flex gap-2">
                    ${u.kyc !== 1 ? `<button class="btn btn-sm btn-success flex-fill" data-act="approve" data-id="${u.id}">اعتماد</button>` : ''}
                    ${u.kyc !== 2 ? `<button class="btn btn-sm btn-outline-danger flex-fill" data-act="reject" data-id="${u.id}">رفض</button>` : ''}
                    <button class="btn btn-sm ${u.is_active ? 'btn-outline-dark' : 'btn-dark'}" data-act="block" data-id="${u.id}">
                        ${u.is_active ? 'حظر' : 'فكّ الحظر'}</button>
                </div>
            </div>
        </div></div>`;
    }

    async function load() {
        const wrap = document.getElementById('cards');
        wrap.innerHTML = '<div class="col-12 text-muted">جارٍ التحميل…</div>';
        const r = await fetch(`${base}/verification/list.json?filter=${filter}&page=${page}`,
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        lastPage = j.last_page;
        document.getElementById('page-info').textContent = `${j.total} حساب — صفحة ${j.current_page} من ${j.last_page}`;
        wrap.innerHTML = j.data.length ? j.data.map(card).join('')
            : '<div class="col-12 text-muted py-4 text-center">لا حسابات في هذا التصنيف 🎉</div>';
    }

    document.getElementById('cards').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const id = btn.dataset.id;
        try {
            if (btn.dataset.act === 'approve') {
                const j = await post(`${base}/users/${id}/kyc`, {status: 1});
                alert(j.message);
            } else if (btn.dataset.act === 'reject') {
                const j = await post(`${base}/users/${id}/kyc`, {status: 2});
                alert(j.message);
            } else if (btn.dataset.act === 'block') {
                const reason = prompt('سبب الحظر/فكّ الحظر (يُسجَّل في التدقيق):') || '';
                const j = await post(`${base}/users/${id}/toggle-active`, {reason});
                alert(j.message);
            }
            load();
        } catch (err) { alert(err.message); }
    });

    document.getElementById('filter').addEventListener('change', (e) => { filter = e.target.value; page = 1; load(); });
    document.getElementById('prev-page').addEventListener('click', () => { if (page > 1) { page--; load(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; load(); } });

    load();
})();
</script>
@endpush
