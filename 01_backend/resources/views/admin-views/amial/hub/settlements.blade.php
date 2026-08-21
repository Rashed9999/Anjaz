@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — لوحة التسويات (تسويات الوكلاء).
     حقيقية: الاعتماد يمرّ عبر AgentNetworkService ويسجّل قيد دفتر مزدوج
     ويضيف الرصيد للوكيل فعلياً. --}}

@section('title', 'لوحة التسويات')

@section('content')
<div class="content container-fluid">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">لوحة التسويات — الوكلاء</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>

    {{-- AMIAL-CASH-HANDOVER-001 — **قيدٌ في الدفتر ليس دليلاً على أنّ
         حقيبةً انتقلت.** ورصيدٌ رقميٌّ سُلّم مقابل نقدٍ لم يؤكّد أحدٌ
         استلامَه هو **مالٌ في الطريق** — وقد يكون في جيب. --}}
    <div id="handover-panel" class="mb-4"></div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">تسويات معلّقة</small>
            <div class="fs-3 fw-bold text-warning" id="s-pending">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">مبلغ معلّق</small>
            <div class="fs-5 fw-bold" id="s-pending-amt">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">تسويات مكتملة</small>
            <div class="fs-3 fw-bold text-success" id="s-done">…</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">مبلغ مكتمل</small>
            <div class="fs-5 fw-bold" id="s-done-amt">…</div>
        </div></div>
    </div>

    <div class="card stat-card">
        <div class="card-header d-flex gap-2 align-items-center">
            <select id="status-filter" class="form-select" style="max-width:220px">
                <option value="pending">المعلّقة (تحتاج قراراً)</option>
                <option value="completed">المكتملة</option>
                <option value="rejected">المرفوضة</option>
            </select>
            <span class="text-muted small ms-auto" id="page-info"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الوكيل</th><th>النوع</th><th>المبلغ</th><th>العمولة</th>
                    <th>الطريقة/المرجع</th><th>التاريخ</th><th>إجراءات</th>
                </tr></thead>
                <tbody id="tbody"><tr><td colspan="7" class="text-center text-muted py-4">جارٍ التحميل…</td></tr></tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <div class="btn-group">
                <button class="btn btn-sm btn-outline-secondary" id="prev-page">السابق</button>
                <button class="btn btn-sm btn-outline-secondary" id="next-page">التالي</button>
            </div>
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
    let page = 1, lastPage = 1, status = 'pending';

    async function post(url, body) {
        const r = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: JSON.stringify(body || {})});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    async function loadHandovers() {
        const box = document.getElementById('handover-panel');

        try {
            const r = await fetch(`${base}/../settlements/handovers/pending`, {headers: {'Accept': 'application/json'}});
            const j = await r.json();
            const items = (j.meta && j.meta.items) || [];

            if (!items.length) {
                box.innerHTML = '';
                return;
            }

            box.innerHTML = `<div class="card border-warning">
                <div class="card-body">
                    <h6 class="mb-1">🚚 نقدٌ في الطريق — ${items.length} تسليماً لم يُؤكَّد استلامُه</h6>
                    <div class="small text-muted mb-3">
                        الرصيدُ الرقميُّ تحرّك، والنقدُ الورقيُّ ينتظر تأكيدَ المستلِم.
                        <strong>ومن سلّم لا يستلم.</strong>
                    </div>
                    <div class="table-responsive"><table class="table table-sm align-middle" data-testid="handovers-table">
                    <thead><tr><th>الاتجاه</th><th class="text-end">المبلغ</th><th>المرجع</th>
                        <th>منذ</th><th class="text-end">—</th></tr></thead><tbody>
                    ${items.map(h => `<tr>
                        <td class="small">${esc(h.direction_label)}</td>
                        <td class="text-end fw-bold">${fmt(h.amount)} ر.ي</td>
                        <td class="small font-monospace">${esc(h.reference || h.settlement_ulid || '—')}</td>
                        <td class="small ${h.age_hours > 24 ? 'text-danger fw-bold' : 'text-muted'}">
                            ${h.age_hours === null ? '—' : h.age_hours + ' ساعة'}</td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-success js-hv-confirm" data-u="${esc(h.handover_ulid)}">أستلمتُه</button>
                            <button class="btn btn-sm btn-outline-danger js-hv-dispute" data-u="${esc(h.handover_ulid)}">خلاف</button>
                        </td></tr>`).join('')}
                    </tbody></table></div>
                </div></div>`;
        } catch (e) {
            // **الغيابُ يُقال ولا يُقرأ سلامة.** ولوحٌ فارغٌ لأنّ الطلبَ
            // سقط يُقرأ «لا نقدَ في الطريق» — وهو أسوأ من رسالة خطأ.
            box.innerHTML = `<div class="alert alert-secondary py-2 small">
                تعذّر قراءةُ تسليمات النقد — <strong>وهذا ليس «لا تسليمات معلّقة»</strong>.</div>`;
        }
    }

    document.addEventListener('click', async function (e) {
        const c = e.target.closest('.js-hv-confirm');
        const d = e.target.closest('.js-hv-dispute');
        if (!c && !d) return;

        try {
            if (c) {
                if (!confirm('تؤكّد أنّك عددتَ المالَ واستلمتَه؟')) return;
                await post(`${base}/../settlements/handovers/${c.dataset.u}/confirm`, {});
            } else {
                const reason = prompt('سببُ الخلاف (يُحفظ ولا يُمحى):');
                if (!reason) return;
                await post(`${base}/../settlements/handovers/${d.dataset.u}/dispute`, {reason});
            }
            await loadHandovers();
        } catch (err) {
            alert(err.message);
        }
    });

    async function load() {
        loadHandovers();
        const tbody = document.getElementById('tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        const r = await fetch(`${base}/settlements/list.json?status=${status}&page=${page}`, {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        lastPage = j.last_page;
        document.getElementById('page-info').textContent = `${j.total} تسوية — صفحة ${j.current_page} من ${j.last_page}`;
        const s = j.summary || {};
        document.getElementById('s-pending').textContent = s.pending ?? '—';
        document.getElementById('s-pending-amt').textContent = fmt(s.pending_amount) + ' ر.ي';
        document.getElementById('s-done').textContent = s.completed ?? '—';
        document.getElementById('s-done-amt').textContent = fmt(s.completed_amount) + ' ر.ي';

        tbody.innerHTML = j.data.length ? j.data.map(x => `
            <tr>
                <td>${esc(x.agent)}<div class="small text-muted" dir="ltr">${esc(x.phone)}</div></td>
                <td><span class="badge bg-secondary">${esc(x.type)}</span></td>
                <td class="fw-bold">${fmt(x.amount)} ر.ي</td>
                <td>${fmt(x.commission)} ر.ي</td>
                <td class="small">${esc(x.method ?? '—')}<div class="text-muted">${esc(x.reference ?? '')}</div></td>
                <td class="small">${esc(x.created_at ?? '')}</td>
                <td class="text-nowrap">
                    ${x.status === 'pending' ? `
                        <button class="btn btn-sm btn-success" data-act="approve" data-ulid="${esc(x.ulid)}">اعتماد</button>
                        <button class="btn btn-sm btn-outline-danger" data-act="reject" data-ulid="${esc(x.ulid)}">رفض</button>`
                    : (x.status === 'completed' ? '<span class="badge bg-success">مكتملة</span>' : '<span class="badge bg-danger">مرفوضة</span>')}
                </td>
            </tr>`).join('')
            : '<tr><td colspan="7" class="text-center text-muted py-4">لا تسويات في هذا التصنيف</td></tr>';
    }

    document.getElementById('tbody').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const ulid = btn.dataset.ulid;
        try {
            if (btn.dataset.act === 'approve') {
                if (!confirm('اعتماد التسوية وإضافة الرصيد للوكيل؟')) return;
                const j = await post(`${base}/settlements/${ulid}/approve`, {});
                alert(j.message);
            } else {
                const reason = prompt('سبب الرفض:') || '';
                const j = await post(`${base}/settlements/${ulid}/reject`, {reason});
                alert(j.message);
            }
            load();
        } catch (err) { alert(err.message); }
    });

    document.getElementById('status-filter').addEventListener('change', (e) => { status = e.target.value; page = 1; load(); });
    document.getElementById('prev-page').addEventListener('click', () => { if (page > 1) { page--; load(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; load(); } });
    load();
})();
</script>
@endpush
