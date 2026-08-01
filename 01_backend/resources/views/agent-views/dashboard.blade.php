<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>بوّابة الوكيل — أميال باي</title>
    {{-- كان هنا `asset('public/assets/admin/css/theme.min.css')` — ملفٌّ لا
         وجود له في المستودع. فكانت اللوحة تُحمَّل بلا Bootstrap فتظهر نصّاً
         خاماً بنقاطٍ سوداء، وبلا سكربته فلا تتبدّل التبويبات ولا تُفتح
         النوافذ. وهو ما بدا «بدائياً» و«أزراراً لا تعمل». --}}
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
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

<div class="topbar py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <strong>🏦 أميال باي — بوّابة شركات الصرافة</strong>
    {{-- الفرع يُعرَض في الترويسة ولا يُختار في أيّ شاشة: هو هويّة الداخل. --}}
    @if($branchName)
        <span class="badge bg-light text-dark">🏬 {{ $branchName }}</span>
    @endif
    <span class="badge bg-secondary">{{ $roleLabel }}</span>
    <span class="ms-auto small">
        {{ $staffName }}@if($staffUsername) <span class="opacity-75" dir="ltr">({{ $staffUsername }})</span>@endif
    </span>
    <a href="{{ route('agent.logout') }}" class="btn btn-sm btn-outline-light">خروج</a>
</div>

<div class="container-fluid p-3" id="ag" data-testid="agent-portal">

    <div class="row g-3 mb-3" id="ag-totals"></div>

    {{-- التبويبات تختلف بالدور. الصرّاف لا يرى «الفروع» لأنّ له فرعاً
         واحداً هو فرعه، والمدير لا يرى «الشبّاك» لأنّه لا يقف عليه. --}}
    <ul class="nav nav-tabs mb-3">
        @if($role === 'teller')
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ag-counter" data-testid="ag-tab-counter">💵 الشبّاك</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-report" data-testid="ag-tab-report">📋 تقرير اليوم</button></li>
        @else
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ag-staff" data-testid="ag-tab-staff">👤 الموظّفون</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-shifts" data-testid="ag-tab-shifts">🪟 الشبابيك والورديّات</button></li>
            @if($role !== 'branch_manager')
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-branches" data-testid="ag-tab-branches">🏬 الفروع</button></li>
            @endif
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-till" data-testid="ag-tab-till">🧾 حركة النقد</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-earn" data-testid="ag-tab-earn">📈 العمولات</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-settle" data-testid="ag-tab-settle">🤝 التسويات</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-report" data-testid="ag-tab-report">📋 تقرير اليوم</button></li>
        @endif
    </ul>

    <div class="tab-content">

        @if($role === 'teller')
            @include('agent-views._counter')
        @else
            @include('agent-views._staff')
        @endif

        {{-- ما دون هذا للإدارة ومديري الفروع. والصرّاف لا يراه: ليس
             إخفاءَ واجهةٍ بل حدَّ صلاحية — نقاطُ النهاية نفسها تفحص الدور. --}}
        @if($role !== 'teller')
        @if($role === 'head_office')
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

        @endif
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

        {{-- ============ العمولات ============ --}}
        <div class="tab-pane fade" id="ag-earn">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                    <select id="ag-earn-branch" class="form-select" style="max-width:260px"></select>
                    <div><label class="form-label small mb-1">من</label>
                        <input type="date" id="ag-earn-from" class="form-control form-control-sm"></div>
                    <div><label class="form-label small mb-1">إلى</label>
                        <input type="date" id="ag-earn-to" class="form-control form-control-sm"></div>
                    <button class="btn btn-primary btn-sm" id="ag-earn-load">عرض</button>
                </div>
                <div class="alert alert-secondary py-2 small">
                    العمولة تُشتقّ من <strong>قيود الدفتر</strong> لا من عدّادٍ تراكميّ —
                    فالقيود تُلحَق ولا تُعدَّل، والرقم يُعاد حسابه في أيّ وقت ويطابق ما يراه المدقّق.
                </div>
                <div id="ag-earn-summary" class="row g-3 mb-3"></div>
                <div id="ag-earn-days"></div>
            </div>
        </div>

        {{-- ============ التسويات ============ --}}
        <div class="tab-pane fade" id="ag-settle">
            <div class="card p-3">
                <div class="alert alert-secondary py-2 small">
                    <strong>العمولة المستحقّة غير الرصيد الإلكترونيّ:</strong>
                    ذاك سيولةُ تشغيلٍ تتحرّك مع كلّ عملية، وهذه أرباحٌ تُسحب.
                    وخلطُهما يجعل وكيلاً يسحب أرباحه فيعجز عن الإيداع.
                </div>
                <div class="d-flex mb-2"><button class="btn btn-outline-primary btn-sm ms-auto" id="ag-settle-load">تحديث</button></div>
                <div id="ag-settle-summary" class="row g-3 mb-3"></div>
                <div id="ag-settle-list"></div>
            </div>
        </div>

        @endif
        {{-- ============ تقرير اليوم ============ --}}
        <div class="tab-pane fade" id="ag-report">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                    {{-- الصرّاف له فرعٌ واحد: تُملأ القائمة به وتُخفى.
                         سؤالُه «أيّ فرع؟» وله فرعٌ واحدٌ نقرةٌ بلا معنى. --}}
                    <select id="ag-rep-branch" class="form-select" style="max-width:260px"
                            @if($role === 'teller') hidden @endif></select>
                    <div><label class="form-label small mb-1">التاريخ</label>
                        <input type="date" id="ag-rep-date" class="form-control form-control-sm"></div>
                    <button class="btn btn-primary btn-sm" id="ag-rep-load">عرض</button>
                    <button class="btn btn-outline-secondary btn-sm" id="ag-rep-print">طباعة</button>
                </div>
                <div id="ag-rep-body"></div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
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
        document.getElementById('ag-earn-branch').innerHTML = opts;
        document.getElementById('ag-rep-branch').innerHTML = opts;

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

    // ---------- العمولات ----------
    document.getElementById('ag-earn-load').onclick = loadEarnings;

    async function loadEarnings() {
        const id = document.getElementById('ag-earn-branch').value;
        if (!id) return;
        const from = document.getElementById('ag-earn-from').value;
        const to = document.getElementById('ag-earn-to').value;
        const qs = [];
        if (from) qs.push('from=' + from);
        if (to) qs.push('to=' + to);

        const j = await get(`/branches/${id}/commissions` + (qs.length ? '?' + qs.join('&') : ''));
        if (!j.success) return;
        const m = j.meta;

        const tile = (t, v, s2, cls) => `
            <div class="col-lg-3 col-6"><div class="card p-3 h-100 ${cls || ''}">
                <div class="small text-muted">${t}</div><div class="fs-5 fw-bold money">${num(v)}</div>
                ${s2 ? `<div class="small text-muted">${s2}</div>` : ''}</div></div>`;

        document.getElementById('ag-earn-summary').innerHTML =
            tile('عمولتك', m.total_commission, m.operations + ' عملية', 'border-success') +
            tile('إجمالي الرسوم', m.total_fee, 'ما دفعه العملاء') +
            // ما يذهب للمنصّة يُعرَض صراحةً: وكيلٌ يرى ما كسبه ولا يرى ما
            // دفعه يظنّ الرسم كلّه له، فيحتجّ يوم التسوية.
            tile('حصّة المنصّة', m.platform_share, 'من الرسوم') +
            tile('عدد العمليات', m.operations, m.from + ' → ' + m.to);

        document.getElementById('ag-earn-days').innerHTML = `
            <div class="table-responsive"><table class="table table-sm" data-testid="ag-earn-table">
                <thead class="thead-light"><tr><th>اليوم</th><th>إيداعات</th><th>سحوبات</th>
                    <th class="text-end">الحجم</th><th class="text-end">الرسوم</th><th class="text-end">عمولتك</th></tr></thead>
                <tbody>${(m.days || []).map(d => `
                    <tr><td>${esc(d.date)}</td><td>${d.deposits}</td><td>${d.withdrawals}</td>
                        <td class="text-end money">${num(d.volume)}</td>
                        <td class="text-end money">${num(d.fee)}</td>
                        <td class="text-end money fw-bold cash">${num(d.commission)}</td></tr>`).join('')
                    || '<tr><td colspan="6" class="text-muted text-center py-3">لا عمليات في هذه المدّة</td></tr>'}</tbody>
            </table></div>`;
    }

    // ---------- التسويات ----------
    document.getElementById('ag-settle-load').onclick = loadSettlements;

    async function loadSettlements() {
        const j = await get('/settlements');
        if (!j.success) return;
        const m = j.meta;

        document.getElementById('ag-settle-summary').innerHTML = `
            <div class="col-lg-4 col-6"><div class="card p-3 ${Number(m.summary.pending) > 0 ? 'border-warning' : ''}">
                <div class="small text-muted">بانتظار التسوية</div>
                <div class="fs-5 fw-bold money">${num(m.summary.pending)}</div>
                <div class="small text-muted">${m.summary.pending_count} طلب</div></div></div>
            <div class="col-lg-4 col-6"><div class="card p-3 border-success">
                <div class="small text-muted">سُوِّي فعلاً</div>
                <div class="fs-5 fw-bold money">${num(m.summary.completed)}</div></div></div>`;

        document.getElementById('ag-settle-list').innerHTML = `
            <div class="table-responsive"><table class="table table-sm" data-testid="ag-settle-table">
                <thead class="thead-light"><tr><th>المرجع</th><th>النوع</th><th class="text-end">المبلغ</th>
                    <th class="text-end">العمولة</th><th>الحالة</th><th>الطريقة</th><th>التاريخ</th></tr></thead>
                <tbody>${(m.items || []).map(s2 => `
                    <tr><td class="font-monospace small">${esc(String(s2.ulid).slice(0, 12))}…</td>
                        <td>${esc(s2.type)}</td>
                        <td class="text-end money">${num(s2.amount)}</td>
                        <td class="text-end money cash">${num(s2.commission)}</td>
                        <td><span class="badge bg-${s2.status === 'completed' ? 'success' : (s2.status === 'pending' ? 'warning text-dark' : 'secondary')}">${esc(s2.status)}</span></td>
                        <td class="small">${esc(s2.method)}</td>
                        <td class="small">${esc(String(s2.created_at).slice(0, 10))}</td></tr>`).join('')
                    || '<tr><td colspan="7" class="text-muted text-center py-3">لا تسويات</td></tr>'}</tbody>
            </table></div>`;
    }

    // ---------- تقرير اليوم ----------
    document.getElementById('ag-rep-load').onclick = loadReport;
    document.getElementById('ag-rep-print').onclick = () => window.print();

    async function loadReport() {
        const id = document.getElementById('ag-rep-branch').value;
        if (!id) return;
        const date = document.getElementById('ag-rep-date').value;
        const j = await get(`/branches/${id}/report` + (date ? '?date=' + date : ''));
        if (!j.success) return;
        const m = j.meta, c = m.cash;

        const row = (label, val, cls) =>
            `<tr class="${cls || ''}"><td>${label}</td><td class="text-end money fw-bold">${num(val)}</td></tr>`;

        document.getElementById('ag-rep-body').innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div><h5 class="mb-0">${esc(m.branch.name)}</h5>
                     <div class="small text-muted">${esc(m.branch.code)} — ${esc(m.date)}</div></div>
                <div class="text-end">
                    ${c.reconciles
                        ? '<span class="badge bg-success fs-6">الحركة مطابقة ✓</span>'
                        : `<span class="badge bg-danger fs-6">فرق ${num(Number(c.expected) - Number(c.closing))}</span>`}
                    ${m.counted_today ? '' : '<div class="small text-danger mt-1">⚠️ لم يُجرَد الدرج اليوم</div>'}
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6"><div class="card p-3">
                    <h6>حركة النقد</h6>
                    <table class="table table-sm mb-0">
                        ${row('الرصيد الافتتاحيّ', c.opening)}
                        ${row('+ إيداعات العملاء', c.deposits)}
                        ${row('− سحوبات العملاء', c.withdrawals)}
                        ${row('+ توريد إلى الفرع', c.treasury_in)}
                        ${row('− توريد من الفرع', c.treasury_out)}
                        ${Number(c.adjustments) !== 0 ? row('± تسويات جرد', c.adjustments, 'table-warning') : ''}
                        ${row('= الرصيد المتوقَّع', c.expected, 'table-light')}
                        ${row('الرصيد المسجَّل', c.closing, c.reconciles ? 'table-success' : 'table-danger')}
                    </table>
                </div></div>

                <div class="col-lg-6"><div class="card p-3">
                    <h6>الملخّص</h6>
                    <table class="table table-sm mb-0">
                        <tr><td>عدد الإيداعات</td><td class="text-end fw-bold">${m.counts.deposits}</td></tr>
                        <tr><td>عدد السحوبات</td><td class="text-end fw-bold">${m.counts.withdrawals}</td></tr>
                        ${row('عمولة اليوم', m.commission)}
                        ${row('الرصيد الإلكترونيّ', m.emoney_balance)}
                    </table>
                    <div class="small text-muted mt-2">
                        الرصيد المتوقَّع محسوبٌ من الافتتاحيّ والحركة — لا مقروءٌ من الخزنة.
                        وقراءتُه منها تجعل المطابقة تقارن الرقم بنفسه.
                    </div>
                </div></div>
            </div>`;
    }

    loadOverview();
})();
</script>
</body>
</html>
