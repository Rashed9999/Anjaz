<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>بوّابة الوكيل — أميال باي</title>
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/theme.min.css') }}">
    <style>
        body { background:#f4f6fa; font-family:'Tajawal',system-ui,sans-serif; }
        .topbar { background:#0f1b2d; color:#fff; }
        .money { font-variant-numeric:tabular-nums; }
        .cash { color:#0b7a3b; } .emoney { color:#0d5bd6; }
    </style>
</head>
<body>

{{--
    AMIAL-AGENT-PORTAL-001 — لوحة الوكيل.

    **الرصيدان يُعرَضان جنباً إلى جنب في كلّ مكان — وهذا ليس تفصيلاً بصرياً.**

    للفرع قيدان يتحرّكان في اتّجاهين متعاكسين: النقد الورقيّ يحدّ **السحب**،
    والرصيد الإلكترونيّ يحدّ **الإيداع**. وموظّفٌ يرى رقماً واحداً كبيراً
    يَعِد العميل بما لا يستطيع — يقبل سحباً ودرجُه فارغ، أو إيداعاً ورصيدُه
    الإلكترونيّ صفر.

    ولذلك لا يوجد في هذه الشاشة «الرصيد» مفرداً. في كلّ بطاقةٍ رقمان
    بلونين، وتحت كلٍّ منهما ما يحدّه.
--}}

<div class="topbar py-2 px-3 d-flex align-items-center gap-3">
    <strong>💳 أميال باي — بوّابة الوكيل</strong>
    <span class="ms-auto small" id="ag-name"></span>
    <a href="{{ route('agent.logout') }}" class="btn btn-sm btn-outline-light">خروج</a>
</div>

<div class="container-fluid p-3" id="ag" data-testid="agent-portal">

    <div class="row g-3 mb-3" id="ag-totals"></div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ag-counter" data-testid="ag-tab-counter">💵 الشبّاك</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-branches" data-testid="ag-tab-branches">🏬 الفروع</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-till" data-testid="ag-tab-till">🧾 حركة النقد</button></li>
    </ul>

    <div class="tab-content">

        {{-- ============ الشبّاك ============ --}}
        <div class="tab-pane fade show active" id="ag-counter">
            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="card p-3">
                        <h6>١) اختر الفرع</h6>
                        <select id="ag-branch" class="form-select mb-3" data-testid="ag-branch"></select>

                        <h6>٢) ابحث عن العميل</h6>
                        <div class="input-group mb-2">
                            <input type="text" id="ag-phone" class="form-control form-control-lg"
                                   placeholder="رقم هاتف العميل" data-testid="ag-customer-phone">
                            <button class="btn btn-primary" id="ag-find">بحث</button>
                        </div>
                        <div id="ag-customer"></div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card p-3" id="ag-op" style="display:none">
                        <h6>٣) العملية</h6>

                        <div class="alert alert-secondary py-2 small" id="ag-capacity"></div>

                        <div class="mb-3">
                            <label class="form-label">المبلغ</label>
                            <input type="number" id="ag-amount" class="form-control form-control-lg money"
                                   min="1" step="1" data-testid="ag-amount">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ملاحظة (اختياري)</label>
                            <input type="text" id="ag-note" class="form-control">
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-success btn-lg flex-fill" id="ag-deposit" data-testid="ag-deposit">
                                ⬇️ إيداع — العميل يسلّمك نقداً
                            </button>
                            <button class="btn btn-warning btn-lg flex-fill" id="ag-withdraw" data-testid="ag-withdraw">
                                ⬆️ سحب — تسلّم العميل نقداً
                            </button>
                        </div>

                        <div id="ag-result" class="mt-3"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ الفروع ============ --}}
        <div class="tab-pane fade" id="ag-branches">
            <div class="card p-3">
                <div class="d-flex mb-3">
                    <h6 class="mb-0">فروعك</h6>
                    <button class="btn btn-sm btn-primary ms-auto" id="ag-new-branch" data-testid="ag-new-branch">+ فرع جديد</button>
                </div>
                <div id="ag-branch-list"></div>
            </div>
        </div>

        {{-- ============ حركة النقد ============ --}}
        <div class="tab-pane fade" id="ag-till">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <select id="ag-till-branch" class="form-select" style="max-width:260px"></select>
                    <button class="btn btn-outline-primary" id="ag-till-load">عرض</button>
                    <button class="btn btn-outline-primary ms-auto" id="ag-fund">💳 شحن رصيد إلكترونيّ</button>
                    <button class="btn btn-outline-success" id="ag-cash-in">💵 توريد نقد إلى الفرع</button>
                    <button class="btn btn-outline-secondary" id="ag-cash-out">توريد نقد من الفرع</button>
                    <button class="btn btn-outline-dark" id="ag-count">جرد الدرج</button>
                </div>
                <div id="ag-till-summary" class="row g-3 mb-3"></div>
                <div id="ag-till-moves"></div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('public/assets/admin/js/theme.min.js') }}"></script>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('agent') }}';
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const num = n => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 0});

    let branches = [];
    let customer = null;

    async function get(p) { return (await fetch(BASE + p, {headers: {'Accept': 'application/json'}})).json(); }
    async function post(p, b) {
        return (await fetch(BASE + p, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(b || {}),
        })).json();
    }

    // الرقمان دائماً معاً — انظر شرح أعلى الملفّ.
    const dual = (cash, emoney, title, sub, cls) => `
        <div class="col-lg-3 col-md-6"><div class="card p-3 h-100 ${cls || ''}">
            <div class="small text-muted">${title}</div>
            <div class="d-flex justify-content-between align-items-end mt-1">
                <div><div class="fs-5 fw-bold money cash">${num(cash)}</div>
                     <div class="small text-muted">نقد — يحدّ السحب</div></div>
                <div class="text-end"><div class="fs-5 fw-bold money emoney">${num(emoney)}</div>
                     <div class="small text-muted">رصيد — يحدّ الإيداع</div></div>
            </div>
            ${sub ? `<div class="small text-muted mt-1">${sub}</div>` : ''}
        </div></div>`;

    async function loadOverview() {
        const j = await get('/overview');
        if (!j.success) return;
        const m = j.meta;

        document.getElementById('ag-name').textContent =
            m.agent.name + (m.agent.is_branch_account ? ' (فرع)' : '');

        branches = m.branches;

        document.getElementById('ag-totals').innerHTML =
            dual(m.totals.cash_on_hand, m.totals.emoney, 'إجمالي الفروع',
                 m.totals.branches + ' فرع' +
                 (m.totals.low_cash_branches ? ` • <span class="text-danger">${m.totals.low_cash_branches} نقدها منخفض</span>` : ''),
                 m.totals.low_cash_branches ? 'border-danger' : '');

        const opts = branches.map(b =>
            `<option value="${b.id}">${esc(b.name)} (${esc(b.code)})</option>`).join('');
        document.getElementById('ag-branch').innerHTML = opts;
        document.getElementById('ag-till-branch').innerHTML = opts;

        document.getElementById('ag-branch-list').innerHTML = branches.length ? `
            <div class="table-responsive"><table class="table" data-testid="ag-branch-table">
                <thead class="thead-light"><tr><th>الفرع</th><th>المدينة</th>
                    <th class="text-end">نقد الدرج</th><th class="text-end">الرصيد الإلكترونيّ</th><th>الحالة</th></tr></thead>
                <tbody>${branches.map(b => `
                    <tr class="${b.cash_is_low ? 'table-warning' : ''}">
                        <td><strong>${esc(b.name)}</strong><div class="small text-muted">${esc(b.code)} • ${esc(b.phone)}</div></td>
                        <td>${esc(b.city)}</td>
                        <td class="text-end money cash fw-bold">${num(b.cash_on_hand)}</td>
                        <td class="text-end money emoney fw-bold">${num(b.emoney_balance)}</td>
                        <td>${b.is_active ? '<span class="badge bg-success">يعمل</span>' : '<span class="badge bg-secondary">موقوف</span>'}
                            ${b.cash_is_low ? '<span class="badge bg-warning text-dark">نقد منخفض</span>' : ''}
                            ${b.cash_is_overloaded ? '<span class="badge bg-danger">نقد فوق الحدّ</span>' : ''}</td>
                    </tr>`).join('')}</tbody>
            </table></div>`
            : '<div class="alert alert-secondary">لا فروع بعد — أضف فرعك الأوّل</div>';
    }

    // ---------- الشبّاك ----------
    document.getElementById('ag-find').onclick = findCustomer;
    document.getElementById('ag-phone').addEventListener('keydown', e => { if (e.key === 'Enter') findCustomer(); });

    async function findCustomer() {
        const phone = document.getElementById('ag-phone').value.trim();
        const box = document.getElementById('ag-customer');
        box.innerHTML = '<div class="text-muted small">جارٍ البحث…</div>';

        const j = await get('/customer?phone=' + encodeURIComponent(phone));
        if (!j.success) {
            box.innerHTML = `<div class="alert alert-warning py-2 mb-0">${esc(j.message)}</div>`;
            document.getElementById('ag-op').style.display = 'none';
            return;
        }

        customer = j.meta.customer;
        box.innerHTML = `
            <div class="alert alert-${customer.can_transact ? 'success' : 'danger'} py-2 mb-0">
                <strong>${esc(customer.name)}</strong> — ${esc(customer.phone)}
                <span class="badge bg-${customer.severity}">${esc(customer.status_label)}</span>
                ${customer.can_transact ? '' : '<div class="small mt-1">لا تُنفَّذ عمليات على هذا العميل</div>'}
            </div>`;

        document.getElementById('ag-op').style.display = customer.can_transact ? '' : 'none';
        if (customer.can_transact) showCapacity();
    }

    // ما يستطيعه الفرع **الآن** يُقال قبل إدخال المبلغ لا بعد الرفض.
    function showCapacity() {
        const b = branches.find(x => x.id == document.getElementById('ag-branch').value);
        if (!b) return;
        document.getElementById('ag-capacity').innerHTML = `
            هذا الفرع يستطيع الآن: <strong class="cash">${num(b.cash_on_hand)}</strong> سحباً نقدياً،
            و<strong class="emoney">${num(b.emoney_balance)}</strong> إيداعاً.
            ${b.cash_is_low ? '<div class="text-danger mt-1">⚠️ نقد الدرج منخفض — قد لا تكفي السحوبات الكبيرة.</div>' : ''}`;
    }

    document.getElementById('ag-branch').onchange = showCapacity;

    document.getElementById('ag-deposit').onclick = () => doOp('deposit');
    document.getElementById('ag-withdraw').onclick = () => doOp('withdraw');

    async function doOp(op) {
        const branchId = document.getElementById('ag-branch').value;
        const amount = document.getElementById('ag-amount').value;
        if (!branchId || !amount || Number(amount) <= 0) { alert('اختر الفرع وأدخل مبلغاً'); return; }

        const label = op === 'deposit'
            ? `استلمتَ ${num(amount)} نقداً من ${customer.name}؟`
            : `ستسلّم ${num(amount)} نقداً إلى ${customer.name}؟`;
        if (!confirm(label)) return;

        const j = await post(`/branches/${branchId}/${op}`, {
            customer_phone: customer.phone,
            amount: amount,
            note: document.getElementById('ag-note').value || null,
        });

        document.getElementById('ag-result').innerHTML = `
            <div class="alert alert-${j.success ? 'success' : 'danger'} mb-0">
                ${esc(j.message)}
            </div>`;

        if (j.success) {
            document.getElementById('ag-amount').value = '';
            document.getElementById('ag-note').value = '';
            await loadOverview();
            showCapacity();
        }
    }

    // ---------- الفروع ----------
    document.getElementById('ag-new-branch').onclick = async () => {
        const name = prompt('اسم الفرع:');
        if (!name) return;
        const code = prompt('رمز الفرع (مثل ADEN-01):');
        if (!code) return;
        const phone = prompt('هاتف الفرع (يُستعمل للدخول):');
        if (!phone) return;
        const password = prompt('كلمة مرور الفرع (٨ أحرف على الأقل):');
        if (!password || password.length < 8) { alert('كلمة المرور قصيرة'); return; }

        const j = await post('/branches', {
            name, code, phone, password,
            city: prompt('المدينة (اختياري):') || null,
            address: prompt('العنوان (اختياري):') || null,
        });
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) loadOverview();
    };

    // ---------- حركة النقد ----------
    document.getElementById('ag-till-load').onclick = loadTill;

    async function loadTill() {
        const id = document.getElementById('ag-till-branch').value;
        if (!id) return;
        const j = await get(`/branches/${id}/till`);
        if (!j.success) return;
        const s = j.meta.summary;

        document.getElementById('ag-till-summary').innerHTML =
            dual(s.cash_on_hand, s.emoney_balance, 'رصيد الفرع الآن',
                 s.last_counted_at ? 'آخر جرد: ' + esc(String(s.last_counted_at).slice(0, 10)) : 'لم يُجرَد بعد',
                 s.is_low ? 'border-danger' : (s.is_overloaded ? 'border-warning' : '')) +
            `<div class="col-lg-3 col-md-6"><div class="card p-3 h-100">
                <div class="small text-muted">اليوم — إيداعات</div>
                <div class="fs-5 fw-bold money cash">${num(s.today.deposits)}</div></div></div>
             <div class="col-lg-3 col-md-6"><div class="card p-3 h-100">
                <div class="small text-muted">اليوم — سحوبات</div>
                <div class="fs-5 fw-bold money">${num(s.today.withdrawals)}</div></div></div>` +
            (s.is_overloaded ? `<div class="col-12"><div class="alert alert-danger mb-0">
                النقد فوق الحدّ الأعلى — وَرِّد إلى الخزنة. فرعٌ يحتفظ بنقدٍ كبير خطرٌ أمنيّ لا ميزة سيولة.
            </div></div>` : '');

        document.getElementById('ag-till-moves').innerHTML = `
            <div class="table-responsive"><table class="table table-sm" data-testid="ag-till-table">
                <thead class="thead-light"><tr><th>الحركة</th><th class="text-end">المبلغ</th>
                    <th class="text-end">الرصيد بعدها</th><th>المرجع</th><th>الموظّف</th><th>التاريخ</th></tr></thead>
                <tbody>${(j.meta.movements || []).map(m => `
                    <tr><td><span class="badge bg-${m.direction === 'in' ? 'success' : 'secondary'}">${m.direction === 'in' ? 'داخل' : 'خارج'}</span>
                        ${esc(m.reason_label)}${m.note ? `<div class="small text-muted">${esc(m.note)}</div>` : ''}</td>
                        <td class="text-end money">${num(m.amount)}</td>
                        <td class="text-end money fw-bold">${num(m.balance_after)}</td>
                        <td class="small font-monospace">${esc(m.reference || '—')}</td>
                        <td class="small">${esc(m.actor)}</td>
                        <td class="small">${esc(String(m.at || '').slice(0, 16).replace('T', ' '))}</td></tr>`).join('')
                    || '<tr><td colspan="6" class="text-muted text-center py-3">لا حركة</td></tr>'}</tbody>
            </table></div>`;
    }

    async function cashMove(dir) {
        const id = document.getElementById('ag-till-branch').value;
        if (!id) return;
        const amount = prompt(dir === 'in' ? 'مبلغ التوريد إلى الفرع:' : 'مبلغ التوريد من الفرع:');
        if (!amount) return;
        const note = prompt('السبب (٥ أحرف على الأقل):');
        if (!note || note.length < 5) { alert('السبب إلزامي'); return; }

        const j = await post(`/branches/${id}/cash`, {direction: dir, amount, note});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) { loadTill(); loadOverview(); }
    }

    // شحن الرصيد **غير** توريد النقد: هذا يمكّن الفرع من **الإيداع**، وذاك
    // يمكّنه من **السحب**. وخلطُهما يجعل مديراً يورّد نقداً ويظنّ أنّه مكّن
    // فرعه من قبول الإيداعات.
    document.getElementById('ag-fund').onclick = async () => {
        const id = document.getElementById('ag-till-branch').value;
        if (!id) return;
        const amount = prompt('مبلغ الشحن (رصيد إلكترونيّ — يمكّن الفرع من قبول الإيداعات):');
        if (!amount) return;
        const note = prompt('السبب (٥ أحرف على الأقل):');
        if (!note || note.length < 5) { alert('السبب إلزامي'); return; }

        const j = await post(`/branches/${id}/fund`, {amount, note});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) { loadTill(); loadOverview(); }
    };

    document.getElementById('ag-cash-in').onclick = () => cashMove('in');
    document.getElementById('ag-cash-out').onclick = () => cashMove('out');

    document.getElementById('ag-count').onclick = async () => {
        const id = document.getElementById('ag-till-branch').value;
        if (!id) return;
        const counted = prompt('المبلغ المعدود فعلاً في الدرج:');
        if (counted === null) return;
        const note = prompt('ملاحظة الجرد (١٠ أحرف على الأقل — تفسير الفرق هو الفائدة كلّها):');
        if (!note || note.length < 10) { alert('الملاحظة إلزامية'); return; }

        const j = await post(`/branches/${id}/count`, {counted_amount: counted, note});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) { loadTill(); loadOverview(); }
    };

    loadOverview();
})();
</script>
</body>
</html>
