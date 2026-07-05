@extends('layouts.admin.app')
{{-- AMIAL-OPS-CONSOLE-001: منصة عمليات الموظفين — خدمة العملاء / العمليات / التذاكر / المراقبة --}}
@section('title', translate('Operations Console'))

@section('content')
<div class="content container-fluid" id="ops-console" data-testid="ops-console">

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-search" data-testid="tab-search">🔍 خدمة العملاء</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tx" data-testid="tab-tx">💳 فحص عملية</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tickets" data-testid="tab-tickets">🎫 التذاكر</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ops" data-testid="tab-ops">📊 المراقبة</button></li>
    </ul>

    <div class="tab-content">

        {{-- ============ 1) خدمة العملاء: بحث + ملف 360° ============ --}}
        <div class="tab-pane fade show active" id="tab-search">
            <div class="card p-3 mb-3">
                <div class="input-group">
                    <input type="text" id="q" class="form-control" data-testid="search-input"
                           placeholder="هاتف / رقم حساب / رقم عملية / رقم إيصال / اسم…">
                    <button class="btn btn-primary" id="btn-search" data-testid="btn-search">بحث</button>
                </div>
                <div id="search-results" class="mt-3"></div>
            </div>
            <div id="customer-360"></div>
        </div>

        {{-- ============ 2) فحص عملية ============ --}}
        <div class="tab-pane fade" id="tab-tx">
            <div class="card p-3 mb-3">
                <div class="input-group">
                    <input type="text" id="tx-ref" class="form-control" placeholder="رقم العملية (TRX…)">
                    <button class="btn btn-primary" id="btn-tx" data-testid="btn-tx">فحص</button>
                </div>
                <div id="tx-result" class="mt-3"></div>
            </div>
        </div>

        {{-- ============ 3) التذاكر ============ --}}
        <div class="tab-pane fade" id="tab-tickets">
            <div class="card p-3 mb-3">
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <select id="tk-status" class="form-select" style="max-width:200px">
                        <option value="">كل الحالات</option>
                        <option value="open">مفتوحة</option>
                        <option value="investigating">قيد التحقيق</option>
                        <option value="waiting_customer">بانتظار العميل</option>
                        <option value="resolved">محلولة</option>
                        <option value="closed">مغلقة</option>
                    </select>
                    <button class="btn btn-outline-primary" id="btn-tickets" data-testid="btn-tickets">تحديث</button>
                </div>
                <div id="tickets-list"></div>
            </div>
        </div>

        {{-- ============ 4) المراقبة ============ --}}
        <div class="tab-pane fade" id="tab-ops">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small">تحديث تلقائي كل 30 ثانية</span>
                <button class="btn btn-sm btn-outline-primary" id="btn-ops" data-testid="btn-ops">تحديث الآن</button>
            </div>
            <div id="ops-body" class="row g-3"></div>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/support-center') }}';
    const CSRF = '{{ csrf_token() }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function get(path) {
        const r = await fetch(BASE + path, {headers: {'Accept': 'application/json'}});
        return r.json();
    }
    async function post(path, body) {
        const r = await fetch(BASE + path, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(body || {}),
        });
        return r.json();
    }

    // ---------- بحث ----------
    document.getElementById('btn-search').onclick = doSearch;
    document.getElementById('q').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });

    async function doSearch() {
        const q = document.getElementById('q').value.trim();
        if (q.length < 2) return;
        const box = document.getElementById('search-results');
        box.innerHTML = '<div class="text-muted">جارٍ البحث…</div>';
        const j = await get('/search?q=' + encodeURIComponent(q));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }
        const m = j.meta;
        let html = '';
        if (m.users.length) {
            html += '<h6>العملاء</h6><div class="list-group mb-3">' + m.users.map(u => `
                <button class="list-group-item list-group-item-action d-flex justify-content-between js-user-row" data-user-id="${u.id}" data-testid="user-row-${u.id}">
                    <span>${esc(u.name)} <span class="badge bg-secondary">${esc(u.type_label)}</span>
                        ${u.is_temp_blocked ? '<span class="badge bg-danger">مجمَّد</span>' : ''}
                        ${u.is_kyc_verified ? '<span class="badge bg-success">KYC ✓</span>' : '<span class="badge bg-warning text-dark">KYC ✗</span>'}
                    </span><span class="text-muted">${esc(u.phone)}</span>
                </button>`).join('') + '</div>';
        }
        if (m.transactions.length) {
            html += '<h6>العمليات</h6><ul class="list-group mb-3">' + m.transactions.map(t =>
                `<li class="list-group-item">${esc(t.transaction_id)} — ${esc(t.type)} — مدين ${esc(t.debit)} / دائن ${esc(t.credit)}</li>`).join('') + '</ul>';
        }
        if (m.receipts.length) {
            html += '<h6>الإيصالات</h6><ul class="list-group mb-3">' + m.receipts.map(r =>
                `<li class="list-group-item">${esc(r.receipt_number)} — ${esc(r.receipt_type)} — ${esc(r.amount)}</li>`).join('') + '</ul>';
        }
        box.innerHTML = html || '<div class="alert alert-secondary">لا نتائج</div>';
    }

    // ---------- ملف العميل 360° ----------
    window.loadCustomer = async function (id) {
        const box = document.getElementById('customer-360');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/customers/' + id);
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }
        const m = j.meta, p = m.profile;
        box.innerHTML = `
        <div class="card p-3" data-testid="customer-360">
            <div class="d-flex justify-content-between flex-wrap">
                <h5>${esc(p.name)} <small class="text-muted">#${p.id} — ${esc(p.phone)}</small></h5>
                <div>
                    ${m.security.is_temp_blocked ? '<span class="badge bg-danger">مجمَّد مؤقتاً</span>' : '<span class="badge bg-success">نشط</span>'}
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">الرصيد</div><div class="fs-5 fw-bold">${esc(m.wallet.current_balance ?? '0')}</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">معلَّق / محجوز</div><div>${esc(m.wallet.pending_balance ?? '0')} / ${esc(m.wallet.held_balance ?? '0')}</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">KYC</div><div>${m.kyc.is_verified ? '✓ موثَّق' : '✗ غير موثَّق'} (فئة ${esc(m.kyc.tier ?? '—')})</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">PIN</div><div>${m.pin.is_set ? 'معيَّن' : 'غير معيَّن'}${m.pin.locked_until ? ' — مقفول' : ''} (${m.pin.failed_attempts} محاولات فاشلة)</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">الجلسات النشطة</div><div>${m.security.active_sessions}</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">آخر نشاط</div><div>${esc(m.profile.last_active_at ?? '—')}</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">تذاكر مفتوحة</div><div>${m.open_tickets}</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">المنطقة</div><div>${esc(m.profile.zone_code ?? '—')}</div></div></div>
            </div>

            <div class="mt-3 d-flex gap-2 flex-wrap">
                <button class="btn btn-sm btn-outline-danger js-act" data-act="freeze" data-id="${p.id}" data-unfreeze="${m.security.is_temp_blocked ? 1 : ''}" data-testid="btn-freeze">
                    ${m.security.is_temp_blocked ? 'فك التجميد' : 'تجميد مؤقت'}</button>
                <button class="btn btn-sm btn-outline-warning js-act" data-act="reset-pin" data-id="${p.id}" data-testid="btn-reset-pin">إعادة تعيين PIN</button>
                <button class="btn btn-sm btn-outline-secondary js-act" data-act="revoke-sessions" data-id="${p.id}" data-testid="btn-revoke">إنهاء كل الجلسات</button>
                <button class="btn btn-sm btn-outline-info js-act" data-act="require-kyc" data-id="${p.id}" data-testid="btn-kyc">طلب رفع الهوية</button>
                <button class="btn btn-sm btn-primary js-act" data-act="open-ticket" data-id="${p.id}" data-name="${esc(p.name)}" data-testid="btn-open-ticket">+ فتح تذكرة</button>
            </div>

            <h6 class="mt-4">آخر العمليات</h6>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>المرجع</th><th>النوع</th><th>مدين</th><th>دائن</th><th>القرار</th><th>التاريخ</th></tr></thead>
                <tbody>${(m.recent_transactions || []).map(t => `
                    <tr><td class="font-monospace">${esc(t.transaction_id)}</td><td>${esc(t.type)}</td>
                    <td>${esc(t.debit)}</td><td>${esc(t.credit)}</td><td>${esc(t.decision_code ?? '—')}</td><td class="small">${esc(t.created_at)}</td></tr>`).join('')
                    || '<tr><td colspan="6" class="text-muted">لا عمليات</td></tr>'}</tbody>
            </table></div>
        </div>`;
    };

    // ---------- إجراءات ----------
    window.doAction = async function (id, action, unfreeze) {
        const labels = {'freeze': unfreeze ? 'فك التجميد' : 'التجميد المؤقت', 'reset-pin': 'إعادة تعيين PIN',
                        'revoke-sessions': 'إنهاء الجلسات', 'require-kyc': 'طلب رفع الهوية'};
        const reason = prompt('سبب ' + (labels[action] || action) + ' (إلزامي — يُسجَّل في التدقيق):');
        if (!reason || reason.trim().length < 5) { alert('السبب إلزامي (5 أحرف على الأقل)'); return; }
        const body = {reason: reason.trim()};
        if (action === 'freeze' && unfreeze) body.unfreeze = true;
        const j = await post(`/customers/${id}/${action}`, body);
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) loadCustomer(id);
    };

    window.openTicket = async function (userId, name) {
        const subject = prompt('موضوع التذكرة للعميل ' + name + ':');
        if (!subject) return;
        const ref = prompt('رقم العملية المرتبطة (اختياري):') || null;
        const j = await post('/tickets', {user_id: userId, subject: subject, transaction_ref: ref, category: 'other'});
        alert(j.success ? ('فُتحت التذكرة ' + j.meta.ticket.ticket_number) : (j.message || 'فشل'));
    };

    // تفويض أحداث (CSP يمنع onclick المضمّنة): صفوف البحث + أزرار الإجراءات
    document.addEventListener('click', function (e) {
        const row = e.target.closest('.js-user-row');
        if (row) { loadCustomer(parseInt(row.dataset.userId, 10)); return; }

        const act = e.target.closest('.js-act');
        if (act) {
            const id = parseInt(act.dataset.id, 10);
            if (act.dataset.act === 'open-ticket') openTicket(id, act.dataset.name || '');
            else doAction(id, act.dataset.act, !!act.dataset.unfreeze);
        }
    });

    // ---------- فحص عملية ----------
    document.getElementById('btn-tx').onclick = async function () {
        const ref = document.getElementById('tx-ref').value.trim();
        if (!ref) return;
        const box = document.getElementById('tx-result');
        box.innerHTML = '<div class="text-muted">جارٍ الفحص…</div>';
        const j = await get('/transactions/' + encodeURIComponent(ref));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }
        const t = j.meta.transaction;
        box.innerHTML = `
            <div class="row g-2 mb-3">
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">المرجع</div><div class="font-monospace">${esc(t.transaction_id)}</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">النوع</div><div>${esc(t.type)}</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">مدين / دائن</div><div>${esc(t.debit)} / ${esc(t.credit)}</div></div></div>
                <div class="col-md-3 col-6"><div class="border rounded p-2"><div class="small text-muted">القرار</div><div>${esc(t.decision_code ?? 'OK')} ${t.decision_reason ? '— ' + esc(t.decision_reason) : ''}</div></div></div>
            </div>
            <h6>الخط الزمني</h6>
            <ul class="list-group">${j.meta.timeline.map(e => `
                <li class="list-group-item d-flex justify-content-between">
                    <span><strong>${esc(e.event)}</strong> — ${esc(e.detail)}</span>
                    <span class="text-muted small">${esc(e.at)}</span>
                </li>`).join('')}</ul>`;
    };

    // ---------- التذاكر ----------
    document.getElementById('btn-tickets').onclick = loadTickets;
    async function loadTickets() {
        const st = document.getElementById('tk-status').value;
        const box = document.getElementById('tickets-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/tickets' + (st ? '?status=' + st : ''));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }
        const rows = j.meta.tickets.map(t => `
            <tr>
                <td class="font-monospace">${esc(t.ticket_number)}</td>
                <td>${esc(t.user ? (t.user.f_name + ' ' + (t.user.l_name || '')) : t.user_id)}</td>
                <td>${esc(t.subject)}</td>
                <td><span class="badge bg-${({'open':'primary','investigating':'warning','waiting_customer':'secondary','resolved':'success','closed':'dark'})[t.status] || 'light'}">${esc(t.status)}</span></td>
                <td>${esc(t.priority)}</td>
                <td class="small">${esc(t.updated_at)}</td>
            </tr>`).join('');
        box.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover">
            <thead><tr><th>الرقم</th><th>العميل</th><th>الموضوع</th><th>الحالة</th><th>الأولوية</th><th>آخر تحديث</th></tr></thead>
            <tbody>${rows || '<tr><td colspan="6" class="text-muted">لا تذاكر</td></tr>'}</tbody></table></div>`;
    }

    // ---------- المراقبة ----------
    document.getElementById('btn-ops').onclick = loadOps;
    async function loadOps() {
        const j = await get('/ops-dashboard');
        if (!j.success) return;
        const m = j.meta;
        const tile = (label, value, cls) => `
            <div class="col-md-3 col-6"><div class="card p-3 ${cls || ''}">
                <div class="small text-muted">${label}</div><div class="fs-4 fw-bold">${value}</div>
            </div></div>`;
        document.getElementById('ops-body').innerHTML =
            tile('عمليات اليوم', m.transactions.today) +
            tile('آخر ساعة', m.transactions.last_hour) +
            tile('آخر 5 دقائق', m.transactions.last_5_minutes) +
            tile('فاشلة اليوم', m.transactions.failed_today, m.transactions.failed_today > 0 ? 'border-danger' : '') +
            tile('طابور المهام', m.queues.pending_jobs) +
            tile('مهام فاشلة', m.queues.failed_jobs, m.queues.failed_jobs > 0 ? 'border-danger' : '') +
            tile('مستخدمون نشطون (15د)', m.activity.active_users_15m) +
            tile('نقاط بيع نشطة', m.activity.active_pos_terminals) +
            tile('نزاعات مفتوحة', m.support.open_disputes) +
            tile('قاعدة البيانات', m.health.database === 'up' ? '✓ تعمل' : '✗ متوقفة', m.health.database === 'up' ? 'border-success' : 'border-danger') +
            tile('تذاكر مفتوحة', (m.support.tickets_by_status && m.support.tickets_by_status.open) || 0) +
            tile('قيد التحقيق', (m.support.tickets_by_status && m.support.tickets_by_status.investigating) || 0);
    }
    setInterval(() => { if (document.querySelector('#tab-ops.active, #tab-ops.show')) loadOps(); }, 30000);
})();
</script>
@endsection
