{{-- AMIAL-AGENT-STAFF-001 — الموظّفون والشبابيك.

     الشركة تُعيّن وتُعطّل بنفسها. ولو مرّ تعيينُ صرّافٍ بتذكرة دعمٍ عندنا
     لتعطّل فرعٌ في المكلا انتظاراً لموظّفٍ في صنعاء. --}}

<div class="tab-pane fade show active" id="ag-staff">
    <div class="card p-3">
        <div class="d-flex mb-3 align-items-center flex-wrap gap-2">
            <h6 class="mb-0">موظّفو الشركة</h6>
            <span class="text-muted small" id="st-count"></span>
            <button class="btn btn-sm btn-primary ms-auto" id="st-add" data-testid="st-add">+ تعيين موظّف</button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>رمز الدخول</th><th>الاسم</th><th>الدور</th><th>الفرع</th>
                    <th>حدّ العملية</th><th>آخر دخول</th><th>الحالة</th><th></th>
                </tr></thead>
                <tbody id="st-tbody">
                    <tr><td colspan="8" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="tab-pane fade" id="ag-shifts">
    <div class="card p-3">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <h6 class="mb-0">ورديّات الشبابيك</h6>
            <select id="sh-branch" class="form-select" style="max-width:260px"></select>
            <input type="date" id="sh-date" class="form-control" style="max-width:180px">
            <button class="btn btn-outline-primary btn-sm" id="sh-load">عرض</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>#</th><th>الصرّاف</th><th>العهدة</th><th>في الدرج</th>
                    <th>إيداعات</th><th>سحوبات</th><th>المتوقَّع</th><th>المعدود</th>
                    <th>الفرق</th><th>الحالة</th><th></th>
                </tr></thead>
                <tbody id="sh-tbody">
                    <tr><td colspan="11" class="text-center text-muted py-4">اختر فرعاً</td></tr>
                </tbody>
            </table>
        </div>
        <div class="text-muted small mt-2">
            الفرق يُسجَّل حركةَ نقدٍ بسببه ولا يُبتلَع — ولا يُعرَض المتوقَّع للصرّاف قبل أن يعدّ.
        </div>
    </div>
</div>

{{-- نافذة تعيين موظّف --}}
<div class="modal fade" id="st-modal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">تعيين موظّف</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label">الاسم</label>
                <input class="form-control" id="st-name"></div>
            <div class="mb-2"><label class="form-label">الدور</label>
                <select class="form-select" id="st-role">
                    <option value="teller">صرّاف (شبّاك)</option>
                    <option value="branch_manager">مدير فرع</option>
                </select></div>
            <div class="mb-2" id="st-branch-wrap"><label class="form-label">الفرع</label>
                <select class="form-select" id="st-branch"></select></div>
            <div class="mb-2"><label class="form-label">الهاتف (اختياري)</label>
                <input class="form-control" id="st-phone" dir="ltr"></div>
            <div class="mb-2"><label class="form-label">كلمة السرّ (٦ أحرف فأكثر)</label>
                <input class="form-control" id="st-pass" dir="ltr"></div>
            <div class="mb-2"><label class="form-label">حدّ العملية الواحدة (٠ = بلا حدّ خاصّ)</label>
                <input class="form-control" id="st-limit" type="number" min="0" value="0" dir="ltr">
                <div class="form-text">صرّافٌ جديد يُعطى حدّاً منخفضاً حتى يُوثَق به.</div></div>
            <div class="text-danger small" id="st-err"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="st-save">تعيين</button>
        </div>
    </div></div>
</div>

@once
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
document.addEventListener('DOMContentLoaded', function () {
    const root = '{{ url('agent') }}';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = (id) => document.getElementById(id);
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    if (!$('st-tbody')) return;   // صرّافٌ — لا شاشة موظّفين في صفحته

    async function post(url, body) {
        const r = await fetch(root + url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }
    const get = async (u) => (await fetch(root + u, {headers: {'Accept': 'application/json'}})).json();

    let branches = [];

    async function loadBranches() {
        const j = await get('/overview');
        branches = (j.data ? j.data.branches : j.branches) || [];
        const opts = branches.map(b => `<option value="${b.id}">${esc(b.name)} (${esc(b.code)})</option>`).join('');
        ['sh-branch', 'st-branch'].forEach(id => { if ($(id)) $(id).innerHTML = opts; });
    }

    async function loadStaff() {
        const j = await get('/staff');
        const rows = j.data || [];
        $('st-count').textContent = `${rows.length} موظّف`;

        $('st-tbody').innerHTML = rows.length ? rows.map(s => `
            <tr>
                <td><span class="badge bg-dark font-monospace" dir="ltr">${esc(s.username)}</span></td>
                <td>${esc(s.name)}<div class="small text-muted" dir="ltr">${esc(s.phone ?? '')}</div></td>
                <td>${esc(s.role_label)}</td>
                <td>${esc(s.branch ?? '—')}</td>
                <td>${Number(s.max_txn_amount) > 0 ? fmt(s.max_txn_amount) : '<span class="text-muted">بلا حدّ خاصّ</span>'}</td>
                <td class="small">${esc(s.last_login_at ?? 'لم يدخل بعد')}</td>
                <td>
                    ${s.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-danger">معطَّل</span>'}
                    ${s.has_open_shift ? '<span class="badge bg-warning text-dark">ورديّة مفتوحة</span>' : ''}
                </td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary" data-do="pass" data-id="${s.id}">كلمة سرّ</button>
                    <button class="btn btn-sm ${s.is_active ? 'btn-outline-danger' : 'btn-outline-success'}"
                            data-do="toggle" data-id="${s.id}" data-active="${s.is_active ? 0 : 1}">
                        ${s.is_active ? 'تعطيل' : 'تفعيل'}</button>
                </td>
            </tr>`).join('')
            : '<tr><td colspan="8" class="text-center text-muted py-4">لا موظّفين بعد — عيّن أوّل صرّافٍ ليعمل الشبّاك</td></tr>';
    }

    $('st-tbody').addEventListener('click', async (e) => {
        const b = e.target.closest('button[data-do]');
        if (!b) return;
        try {
            if (b.dataset.do === 'toggle') {
                const j = await post(`/staff/${b.dataset.id}/active`, {active: b.dataset.active === '1'});
                alert(j.message);
            } else {
                const p = prompt('كلمة السرّ الجديدة (٦ أحرف فأكثر):');
                if (!p) return;
                const j = await post(`/staff/${b.dataset.id}/password`, {password: p});
                alert(j.message);
            }
            loadStaff();
        } catch (err) { alert(err.message); }
    });

    $('st-add').addEventListener('click', () => {
        $('st-err').textContent = '';
        new bootstrap.Modal($('st-modal')).show();
    });

    $('st-save').addEventListener('click', async () => {
        $('st-err').textContent = '';
        try {
            const j = await post('/staff', {
                name: $('st-name').value,
                role: $('st-role').value,
                branch_id: $('st-branch').value || null,
                phone: $('st-phone').value || null,
                password: $('st-pass').value,
                max_txn_amount: $('st-limit').value || 0,
            });
            bootstrap.Modal.getInstance($('st-modal')).hide();
            alert(j.message);
            $('st-name').value = $('st-pass').value = $('st-phone').value = '';
            loadStaff();
        } catch (e) { $('st-err').textContent = e.message; }
    });

    async function loadShifts() {
        const bid = $('sh-branch').value;
        if (!bid) return;
        const d = $('sh-date').value;
        const j = await get(`/staff/shifts?branch_id=${bid}` + (d ? `&date=${d}` : ''));
        const rows = j.data || [];

        $('sh-tbody').innerHTML = rows.length ? rows.map(s => `
            <tr>
                <td>${s.id}</td>
                <td>${esc(s.staff ?? '—')}<div class="small text-muted font-monospace" dir="ltr">${esc(s.username ?? '')}</div></td>
                <td class="money">${fmt(s.opening_float)}</td>
                <td class="money cash">${fmt(s.cash_on_hand)}</td>
                <td class="money">${fmt(s.deposits_total)} <span class="text-muted small">(${s.deposits_count})</span></td>
                <td class="money">${fmt(s.withdrawals_total)} <span class="text-muted small">(${s.withdrawals_count})</span></td>
                <td class="money">${fmt(s.expected_cash)}</td>
                <td class="money">${s.counted_cash === null ? '<span class="text-muted">لم يُعدّ</span>' : fmt(s.counted_cash)}</td>
                <td class="money ${s.variance && Number(s.variance) !== 0 ? 'text-danger fw-bold' : ''}">
                    ${s.variance === null ? '—' : fmt(s.variance)}</td>
                <td>${s.status === 'open' ? '<span class="badge bg-success">مفتوحة</span>'
                                          : '<span class="badge bg-secondary">مغلقة</span>'}</td>
                <td>${s.status === 'open'
                        ? `<button class="btn btn-sm btn-outline-danger" data-close="${s.id}">إغلاق بالنيابة</button>` : ''}</td>
            </tr>`).join('')
            : '<tr><td colspan="11" class="text-center text-muted py-4">لا ورديّات</td></tr>';
    }

    $('sh-tbody').addEventListener('click', async (e) => {
        const b = e.target.closest('button[data-close]');
        if (!b) return;
        // صرّافٌ غادر ولم يُغلق يترك درجاً مقفلاً على النظام لا على الواقع.
        const counted = prompt('اعدّ ما في درج الصرّاف واكتب المبلغ:');
        if (counted === null) return;
        const note = prompt('سبب الإغلاق بالنيابة (عشرة أحرف فأكثر إن وُجد فرق):', '') || '';
        try {
            const j = await post(`/staff/shifts/${b.dataset.close}/close`, {counted_cash: counted, note});
            alert(j.message); loadShifts();
        } catch (err) { alert(err.message); }
    });

    $('sh-load').addEventListener('click', loadShifts);
    $('sh-branch').addEventListener('change', loadShifts);

    // الدور يقرّر ما يُعرَض في نافذة التعيين: مدير الفرع يعيّن صرّافين في
    // فرعه وحده، فلا يُعرض له اختيار فرعٍ ولا دورٌ أعلى منه.
    loadBranches().then(() => {
        if (branches.length <= 1) {
            $('st-branch-wrap').style.display = branches.length ? '' : 'none';
        }
        loadShifts();
    });
    loadStaff();
});
</script>
@endonce
