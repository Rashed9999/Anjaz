@extends('layouts.admin.app')
{{-- AMIAL-OPS-CONSOLE-001: منصة عمليات الموظفين — خدمة العملاء / العمليات / التذاكر / المراقبة --}}
@section('title', translate('Operations Console'))

@section('content')
@php($firstSupportTab = collect($capabilities)->filter()->keys()->first())
<div class="content container-fluid" id="ops-console" data-testid="ops-console">

    <ul class="nav nav-tabs mb-3" role="tablist">
        @if($capabilities['customers'])<li class="nav-item"><button class="nav-link {{ $firstSupportTab === 'customers' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-search" data-testid="tab-search">🔍 خدمة العملاء</button></li>@endif
        @if($capabilities['transactions'])<li class="nav-item"><button class="nav-link {{ $firstSupportTab === 'transactions' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-tx" data-testid="tab-tx">💳 فحص عملية</button></li>@endif
        @if($capabilities['tickets'])<li class="nav-item"><button class="nav-link {{ $firstSupportTab === 'tickets' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-tickets" data-testid="tab-tickets">🎫 التذاكر</button></li>@endif
        @if($capabilities['approvals'])<li class="nav-item"><button class="nav-link {{ $firstSupportTab === 'approvals' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-approvals" data-testid="tab-approvals">✅ الموافقات</button></li>@endif
        @if($capabilities['insider'])<li class="nav-item"><button class="nav-link {{ $firstSupportTab === 'insider' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-insider" data-testid="tab-insider">🛡 أمن داخلي</button></li>@endif
        @if($capabilities['ops'])<li class="nav-item"><button class="nav-link {{ $firstSupportTab === 'ops' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-ops" data-testid="tab-ops">📊 المراقبة</button></li>@endif
    </ul>

    <div class="tab-content">

        {{-- ============ 1) خدمة العملاء: بحث + ملف 360° ============ --}}
        <div class="tab-pane fade {{ $firstSupportTab === 'customers' ? 'show active' : '' }}" id="tab-search">
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
        <div class="tab-pane fade {{ $firstSupportTab === 'transactions' ? 'show active' : '' }}" id="tab-tx">
            <div class="card p-3 mb-3">
                <div class="input-group">
                    <input type="text" id="tx-ref" class="form-control" placeholder="رقم العملية (TRX…)">
                    <button class="btn btn-primary" id="btn-tx" data-testid="btn-tx">فحص</button>
                </div>
                <div id="tx-result" class="mt-3"></div>
            </div>
        </div>

        {{-- ============ 3) التذاكر ============ --}}
        <div class="tab-pane fade {{ $firstSupportTab === 'tickets' ? 'show active' : '' }}" id="tab-tickets">
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

        {{-- ============ الموافقات (Maker-Checker) ============ --}}
        <div class="tab-pane fade {{ $firstSupportTab === 'approvals' ? 'show active' : '' }}" id="tab-approvals">
            <div class="card p-3 mb-3">
                <div class="d-flex gap-2 mb-3 align-items-center">
                    <span class="text-muted small">الإجراءات الحساسة (فكّ تجميد / إعادة PIN) تتطلب اعتماد مشرف آخر — لا اعتماد ذاتي.</span>
                    <button class="btn btn-outline-primary btn-sm ms-auto" id="btn-approvals" data-testid="btn-approvals">تحديث</button>
                </div>
                <div id="approvals-list"></div>
            </div>
        </div>

        {{-- ============ أمن داخلي (مراقبة الموظفين) ============ --}}
        <div class="tab-pane fade {{ $firstSupportTab === 'insider' ? 'show active' : '' }}" id="tab-insider">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small">كل اطّلاع موظف على ملف عميل مسجَّل — وأي تعديل في سجل التدقيق يُكشف (سلسلة تجزئة).</span>
                <button class="btn btn-sm btn-outline-primary" id="btn-insider" data-testid="btn-insider">تحديث</button>
            </div>
            <div id="insider-chain" class="mb-2"></div>
            <div class="card p-3 mb-3">
                <h6>نشاط الموظفين اليوم</h6>
                <div id="insider-activity"></div>
            </div>
            <div class="card p-3">
                <h6>تنبيهات مفتوحة</h6>
                <div id="insider-alerts"></div>
            </div>
        </div>

        {{-- ============ 4) المراقبة ============ --}}
        <div class="tab-pane fade {{ $firstSupportTab === 'ops' ? 'show active' : '' }}" id="tab-ops">
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
    const CAN_ACK_INSIDER = @json($capabilities['approvals']);
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
        // ══════════════════════════════════════════════════════════
        // AMIAL-SUPPORT-TRACE-REACH-001 — **سطرٌ ميّتٌ فوق تتبّعٍ كامل.**
        //
        // قال صاحبُ المشروع: «دخلتُ الدعمَ وأدخلتُ رقمَ العمليّة فأعطاني
        // قيمتَها فقط، لا معلوماتٍ أخرى». **والتتبّعُ الكاملُ مبنيٌّ**:
        // أطرافٌ ومنفّذُ POS وإيصالٌ وقيودٌ محاسبيّةٌ بأرصدةٍ قبلَ وبعد،
        // ونزاعاتٌ وتذاكرُ وخطٌّ زمنيّ.
        //
        // **وكان في تبويبٍ آخرَ يطلب إعادةَ كتابة الرقم**، ونتيجةُ البحث
        // `<li>` لا تُضغَط. فمن بحث ووجد ظنّ أنّ هذا كلُّ ما عندنا.
        //
        // فصار الصفُّ زرّاً يفتح التتبّعَ بضغطةٍ واحدة. (القاعدة الثانية
        // عشرة: صفحةٌ لا يُوصل إليها ليست مبنيّة.)
        // ══════════════════════════════════════════════════════════
        if (m.transactions.length) {
            html += '<h6>العمليات</h6><div class="list-group mb-3">' + m.transactions.map(t =>
                `<button type="button" class="list-group-item list-group-item-action"
                         data-trace="${esc(t.transaction_id || t.id)}" data-testid="search-tx-row">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                      <span><span class="font-monospace">${esc(t.transaction_id)}</span>
                        — ${esc(t.type)} — مدين ${esc(t.debit)} / دائن ${esc(t.credit)}</span>
                      <span class="badge bg-primary">التتبّع الكامل ←</span>
                    </div>
                 </button>`).join('') + '</div>';
        }
        if (m.receipts.length) {
            html += '<h6>الإيصالات</h6><div class="list-group mb-3">' + m.receipts.map(r =>
                // **والإيصالُ يُتتبَّع بمرجع عمليّته لا برقمه** — فنقطةُ
                // التتبّع تقرأ `transaction_id`، ورقمُ الإيصال لا يطابقها.
                // فمن لا مرجعَ له يبقى سطراً ولا يَعِد بما لا يفتح.
                (r.reference_transaction_id
                    ? `<button type="button" class="list-group-item list-group-item-action"
                               data-trace="${esc(r.reference_transaction_id)}" data-testid="search-receipt-row">
                         <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                           <span>${esc(r.receipt_number)} — ${esc(r.receipt_type)} — ${esc(r.amount)}</span>
                           <span class="badge bg-primary">التتبّع الكامل ←</span>
                         </div>
                       </button>`
                    : `<div class="list-group-item">${esc(r.receipt_number)} — ${esc(r.receipt_type)} — ${esc(r.amount)}
                         <span class="small text-muted">· لا مرجعَ عمليّةٍ مرتبطٌ به</span></div>`)
            ).join('') + '</div>';
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
                <button class="btn btn-sm btn-outline-secondary js-act" data-act="revoke-sessions" data-id="${p.id}" data-testid="btn-revoke">إلغاء الجلسات المسجّلة</button>
                <button class="btn btn-sm btn-outline-info js-act" data-act="require-kyc" data-id="${p.id}" data-testid="btn-kyc">طلب رفع الهوية</button>
                <button class="btn btn-sm btn-primary js-act" data-act="open-ticket" data-id="${p.id}" data-name="${esc(p.name)}" data-testid="btn-open-ticket">+ فتح تذكرة</button>
            </div>

            {{-- AMIAL-DEVICE-PANEL-001: الأجهزة كانت ثلاثة مسارات تردّ JSON
                 ولا شاشة تفتحها — فالحظر مبنيٌّ ولا يُستعمل. --}}
            <div class="d-flex justify-content-between align-items-center mt-4">
                <h6 class="mb-0">الأجهزة</h6>
                <button class="btn btn-sm btn-outline-secondary js-devices" data-id="${p.id}" data-testid="btn-devices">عرض الأجهزة</button>
            </div>
            <div id="devices-box" class="mt-2"></div>

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
        const dialog = window.amialDialog;
        if (!dialog) return;
        const labels = {'freeze': unfreeze ? 'فك التجميد' : 'التجميد المؤقت', 'reset-pin': 'إعادة تعيين PIN',
                        'revoke-sessions': 'إلغاء الجلسات المسجّلة', 'require-kyc': 'طلب رفع الهوية'};
        const reason = await dialog.request(
            'سبب ' + (labels[action] || action) + ' (10 أحرف على الأقل — يُسجَّل في التدقيق):',
            {title: 'تأكيد إجراء على العميل', okLabel: 'تنفيذ', danger: action === 'freeze' && !unfreeze},
        );
        if (reason === null) return;
        if (reason.trim().length < 10) {
            await dialog.show('السبب إلزامي ويجب أن يتكوّن من 10 أحرف على الأقل.', 'بيانات ناقصة');
            return;
        }
        const body = {reason: reason.trim()};
        if (action === 'freeze' && unfreeze) body.unfreeze = true;
        const j = await post(`/customers/${id}/${action}`, body);
        await dialog.show(j.message || (j.success ? 'تم' : 'فشل'), j.success ? 'تم الإجراء' : 'تعذّر الإجراء');
        if (j.success) loadCustomer(id);
    };

    window.openTicket = async function (userId, name) {
        const dialog = window.amialDialog;
        if (!dialog) return;
        const subject = await dialog.request('موضوع التذكرة للعميل ' + name + ':', {title: 'فتح تذكرة دعم', okLabel: 'التالي'});
        if (subject === null || !subject.trim()) return;
        const ref = await dialog.request('رقم العملية المرتبطة (اختياري):', {title: 'فتح تذكرة دعم', okLabel: 'فتح التذكرة'}) || null;
        const j = await post('/tickets', {user_id: userId, subject: subject, transaction_ref: ref, category: 'other'});
        await dialog.show(j.success ? ('فُتحت التذكرة ' + j.meta.ticket.ticket_number) : (j.message || 'فشل'), j.success ? 'تم فتح التذكرة' : 'تعذّر فتح التذكرة');
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

    // ---------- الأجهزة ----------
    //
    // حظرُ جهازٍ بديلٌ عن تجميد الحساب: عميلٌ سُرق هاتفه لا يُعاقب حسابه كلّه،
    // ويبقى يستعمل أميال من جهازه الآخر. ولذلك يُعرَض هنا لا في شاشة منفصلة:
    // القرار يُتّخذ وأنت تنظر إلى رصيده وعملياته، لا بمعزلٍ عنها.
    window.loadDevices = async function (userId) {
        const box = document.getElementById('devices-box');
        if (!box) return;
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/customers/' + userId + '/devices?reason=' + encodeURIComponent('مراجعة أجهزة العميل'));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }

        const rows = (j.meta.devices || []).map(d => `
            <tr class="${d.is_blocked ? 'table-danger' : ''}">
                <td>
                    <div class="fw-bold">${esc(d.device_model)}</div>
                    <div class="small text-muted font-monospace">${esc(String(d.device_id).slice(0, 16))}…</div>
                </td>
                <td class="small">${esc(d.os)}${d.app_version ? ' • v' + esc(d.app_version) : ''}</td>
                <td class="small font-monospace">${esc(d.ip_address)}</td>
                <td class="small">${esc(d.last_seen_at || d.first_seen_at)}</td>
                <td>
                    ${d.is_blocked ? '<span class="badge bg-danger">محظور</span>' : (d.is_active ? '<span class="badge bg-success">نشط</span>' : '<span class="badge bg-secondary">غير نشط</span>')}
                    ${d.is_trusted ? '<span class="badge bg-info">موثوق</span>' : ''}
                    ${d.block_reason ? `<div class="small text-muted mt-1">${esc(d.block_reason)}</div>` : ''}
                </td>
                <td class="text-nowrap">
                    ${d.is_blocked
                        ? `<button class="btn btn-sm btn-outline-success js-dev" data-do="unblock" data-row="${d.id}" data-user="${userId}">رفع الحظر</button>`
                        : `<button class="btn btn-sm btn-outline-danger js-dev" data-do="block" data-row="${d.id}" data-user="${userId}">حظر الجهاز</button>`}
                </td>
            </tr>`).join('');

        box.innerHTML = `
            ${j.meta.blocked > 0 ? `<div class="alert alert-danger py-2 small">${j.meta.blocked} من ${j.meta.total} جهاز محظور.</div>` : ''}
            <div class="table-responsive"><table class="table table-sm" data-testid="devices-table">
                <thead><tr><th>الجهاز</th><th>النظام</th><th>IP</th><th>آخر ظهور</th><th>الحالة</th><th></th></tr></thead>
                <tbody>${rows || '<tr><td colspan="6" class="text-muted text-center py-3">لا أجهزة مسجَّلة</td></tr>'}</tbody>
            </table></div>
            <div class="small text-muted">من حظر الجهاز لا يرفع الحظر عنه — يراجعه موظّف آخر.</div>`;
    };

    document.addEventListener('click', async function (e) {
        const open = e.target.closest('.js-devices');
        if (open) { loadDevices(parseInt(open.dataset.id, 10)); return; }

        const b = e.target.closest('.js-dev');
        if (!b) return;
        const dialog = window.amialDialog;
        if (!dialog) return;
        const isBlock = b.dataset.do === 'block';
        const reason = await dialog.request(
            (isBlock ? 'سبب حظر الجهاز' : 'سبب رفع الحظر') + ' (5 أحرف على الأقل — يُسجَّل في التدقيق):',
            {title: isBlock ? 'حظر جهاز' : 'رفع حظر جهاز', okLabel: 'تنفيذ', danger: isBlock},
        );
        if (reason === null) return;
        if (reason.trim().length < 5) {
            await dialog.show('السبب إلزامي ويجب أن يتكوّن من 5 أحرف على الأقل.', 'بيانات ناقصة');
            return;
        }
        const j = await post(`/devices/${b.dataset.row}/${b.dataset.do}`, {reason: reason.trim()});
        await dialog.show(j.message || (j.success ? 'تم' : 'فشل'), j.success ? 'تم الإجراء' : 'تعذّر الإجراء');
        loadDevices(parseInt(b.dataset.user, 10));
    });

    // ══════════════════════════════════════════════════════════════
    // AMIAL-SUPPORT-TRACE-REACH-001 — **الضغطةُ تنقل وتشغّل معاً.**
    //
    // ونقلٌ بلا تشغيلٍ يترك الدعمَ أمام حقلٍ مملوءٍ وزرٍّ لم يُضغَط —
    // **فيظنّ أنّ لا نتيجة**. فتُملأ الخانةُ ويُضغط الزرُّ في النداء نفسِه.
    //
    // و`data-testid` على الصفوف يجعل مسبارَ الأزرار يمسكها إن ماتت.
    // ══════════════════════════════════════════════════════════════
    document.addEventListener('click', function (e) {
        const row = e.target.closest('[data-trace]');
        if (!row) return;

        const tab = document.querySelector('[data-bs-target="#tab-tx"]');
        if (!tab) {
            // **صلاحيّةُ تتبّع العمليّات غيرُ ممنوحةٍ لهذا الموظّف** —
            // فالتبويبُ غيرُ مُصيَّرٍ أصلاً. ويُقال ولا يُبتلع صمتاً.
            alert('تتبّعُ العمليّات يحتاج صلاحيّة «عرض المعاملات» — راجع مديرَك.');
            return;
        }

        tab.click();
        const box = document.getElementById('tx-ref');
        box.value = row.dataset.trace;
        document.getElementById('btn-tx').click();
    });

    // ---------- فحص عملية ----------
    document.getElementById('btn-tx').onclick = async function () {
        const ref = document.getElementById('tx-ref').value.trim();
        if (!ref) return;
        const box = document.getElementById('tx-result');
        box.innerHTML = '<div class="text-muted">جارٍ الفحص…</div>';
        const j = await get('/transactions/' + encodeURIComponent(ref));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }
        const t = j.meta.transaction, receipt = j.meta.receipt, parties = j.meta.parties || [];
        const card = (label, value, cls = 'col-md-3 col-6') => `<div class="${cls}"><div class="border rounded p-2 h-100"><div class="small text-muted">${esc(label)}</div><div class="text-break">${value}</div></div></div>`;
        const val = v => esc(v === null || v === undefined || v === '' ? '—' : v);
        const recordLabels = {payment_requests:'طلبات الدفع', merchant_sales:'مبيعات التجزئة/البيع السريع', fuel_sales:'مبيعات الوقود', pharmacy_sales:'مبيعات الصيدلية', wholesale_invoices:'فواتير الجملة', wholesale_collections:'تحصيلات الجملة', split_bill_participants:'حصص الفاتورة المقسّمة'};
        const business = Object.entries(j.meta.business_records || {}).filter(([, rows]) => rows && rows.length).map(([kind, rows]) => `
            <div class="card mb-2"><div class="card-header py-2"><strong>${esc(recordLabels[kind] || kind)}</strong></div><div class="table-responsive"><table class="table table-sm mb-0"><tbody>${rows.map(row => {
                const details = Object.entries(row).filter(([key, value]) => !['items','clinical_details_restricted'].includes(key) && value !== null && value !== '').map(([key, value]) => `<div><span class="text-muted">${esc(key)}:</span> ${val(value)}</div>`).join('');
                const items = Array.isArray(row.items) && row.items.length ? `<details class="mt-2"><summary>الأصناف (${row.items.length})</summary><pre class="small mb-0">${esc(JSON.stringify(row.items, null, 2))}</pre></details>` : '';
                const restricted = row.clinical_details_restricted ? '<div class="small text-muted mt-2">تفاصيل الوصفة والأصناف الطبية محمية ولا تُعرض في تتبع المعاملة العام.</div>' : '';
                return `<tr><td>${details}${items}${restricted}</td></tr>`;
            }).join('')}</tbody></table></div></div>`).join('');
        box.innerHTML = `
            <div class="alert alert-light border small">هذا تتبّع كامل للعملية من سجلّ العملية والإيصال والدفتر المحاسبي والسجلات المرتبطة. الحقول الشخصية تظهر فقط لمن يملك صلاحية كشف PII.</div>
            <h6>هوية العملية وحالتها</h6><div class="row g-2 mb-3">
                ${card('رقم العملية الرسمي', `<span class="font-monospace">${val(t.transaction_no)}</span>`)}
                ${card('مرجع العملية', `<span class="font-monospace">${val(t.transaction_id)}</span>`)}
                ${card('المرجع المرتبط', `<span class="font-monospace">${val(t.ref_trans_id)}</span>`)}
                ${card('الرقم الداخلي', val(t.id))}
                ${card('النوع', val(t.type))}${card('الحالة / القرار', val(t.decision_code || 'لم يُسجل قرار'))}
                ${card('سبب القرار', val(t.decision_reason))}${card('أنشئت', val(t.created_at))}
                ${card('آخر تحديث', val(t.updated_at))}${card('منطقة التنفيذ', val(t.zone_code))}
                ${card('منطقة الطلب', val(t.request_zone))}${card('منطقة الطرف الآخر', val(t.counterparty_zone))}
            </div>
            <h6>الأثر المالي</h6><div class="row g-2 mb-3">
                ${card('المبلغ', val(t.amount))}${card('مدين', val(t.debit))}${card('دائن', val(t.credit))}${card('الرسوم', val(t.charge))}
                ${card('الرصيد بعد العملية', val(t.balance_after))}${card('ملاحظة العملية', val(t.note), 'col-md-6 col-12')}
            </div>
            <h6>الأطراف</h6><div class="row g-2 mb-3">${parties.map(p => card(p.role, `<strong>${val(p.name)}</strong><div class="small">#${val(p.user_id)} · ${val(p.type)} · ${val(p.phone)} · ${val(p.zone_code)}</div>`)).join('') || '<div class="text-muted">لا توجد أطراف مرتبطة في السجل القديم.</div>'}</div>
            ${j.meta.pos_actor ? `<h6>منفّذ POS</h6><div class="row g-2 mb-3">${card('جهاز/موظف POS', `<strong>${val(j.meta.pos_actor.display_name)}</strong><div class="small">${val(j.meta.pos_actor.pos_number)} · ${val(j.meta.pos_actor.operator)}</div>`)}${card('مالك المنشأة', `<strong>${val(j.meta.pos_actor.merchant_owner)}</strong><div class="small">#${val(j.meta.pos_actor.merchant_owner_id)}</div>`)}</div>` : ''}
            <h6>الإيصال</h6>${receipt ? `<div class="row g-2 mb-3">${card('رقم الإيصال', `<span class="font-monospace">${val(receipt.receipt_number)}</span>`)}${card('رمز التحقق', `<span class="font-monospace">${val(receipt.verification_code)}</span>`)}${card('حالة العملية', val(receipt.op_status))}${card('حالة PDF', val(receipt.status))}${card('إجمالي الإيصال', val(receipt.amount))}${card('صافي / رسم', `${val(receipt.net_amount)} / ${val(receipt.fee)}`)}${card('الاتجاه', val(receipt.direction))}${card('أصدر في', val(receipt.issued_at))}${card('تنزيلات PDF', val(receipt.download_count))}${card('آخر تنزيل', val(receipt.last_downloaded_at))}</div>` : '<div class="alert alert-warning py-2 mb-3">لا يوجد إيصال مرتبط بهذه العملية.</div>'}
            ${business ? `<h6>سجلات النشاط المرتبطة</h6>${business}` : ''}
            <h6>الدليل المحاسبي</h6>
            <div class="table-responsive mb-3"><table class="table table-sm"><thead><tr><th>القيد</th><th>المصدر</th><th>الحالة</th><th>الأسطر والأرصدة</th></tr></thead><tbody>${(j.meta.ledger_entries || []).map(e => `<tr><td class="font-monospace">${esc(e.ulid)}</td><td>${esc(e.source_type)} / ${esc(e.source_id)}</td><td>${esc(e.status)}${e.is_reversal ? ' — عكسي' : ''}</td><td>${e.lines.map(l => `${esc(l.direction)} ${esc(l.amount)} (${esc(l.account)})<br><small class="text-muted">قبل: ${esc(l.balance_before)} · بعد: ${esc(l.balance_after)}${l.description ? ' · ' + esc(l.description) : ''}</small>`).join('<hr class="my-1">')}</td></tr>`).join('') || '<tr><td colspan="4" class="text-muted">لا يوجد قيد مرتبط — يلزم تحقيق مالي.</td></tr>'}</tbody></table></div>
            ${(j.meta.disputes || []).length || (j.meta.tickets || []).length ? `<h6>النزاعات والتذاكر</h6><div class="row g-2 mb-3">${(j.meta.disputes || []).map(d => card('نزاع #'+d.id, `${val(d.status)}<div class="small">${val(d.reason)} · ${val(d.created_at)}</div>`)).join('')}${(j.meta.tickets || []).map(x => card('تذكرة '+x.number, `${val(x.status)} · ${val(x.category)} · ${val(x.priority)}<div class="small">${val(x.created_at)}</div>`)).join('')}</div>` : ''}
            <h6>الخط الزمني</h6>
            <ul class="list-group">${j.meta.timeline.map(e => `<li class="list-group-item d-flex justify-content-between gap-3"><span><strong>${esc(e.event)}</strong> — ${esc(e.detail)}</span><span class="text-muted small text-nowrap">${esc(e.at)}</span></li>`).join('')}</ul>`;
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

    // ---------- الموافقات (Maker-Checker) ----------
    document.getElementById('btn-approvals').onclick = loadApprovals;
    async function loadApprovals() {
        const box = document.getElementById('approvals-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/approvals?status=pending');
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }
        const labels = {unfreeze_wallet: 'فكّ تجميد حساب', reset_pin: 'إعادة تعيين PIN'};
        const rows = j.meta.approvals.map(a => `
            <tr>
                <td class="font-monospace">${esc(a.request_number)}</td>
                <td>${esc(labels[a.action_type] || a.action_type)}</td>
                <td>${esc(a.subject ? (a.subject.f_name + ' ' + (a.subject.l_name || '')) : a.subject_user_id)}</td>
                <td>${esc(a.maker ? (a.maker.f_name + ' ' + (a.maker.l_name || '')) : a.maker_admin_id)}</td>
                <td class="small">${esc(a.reason)}</td>
                <td class="small">${esc(a.created_at)}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-success js-apr" data-apr="approve" data-id="${a.id}">اعتماد</button>
                    <button class="btn btn-sm btn-outline-danger js-apr" data-apr="reject" data-id="${a.id}">رفض</button>
                </td>
            </tr>`).join('');
        box.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover">
            <thead><tr><th>الطلب</th><th>الإجراء</th><th>العميل</th><th>المُقدِّم</th><th>السبب</th><th>التاريخ</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="7" class="text-muted">لا طلبات معلّقة</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-apr');
        if (!b) return;
        const dialog = window.amialDialog;
        if (!dialog) return;
        const id = b.dataset.id;
        if (b.dataset.apr === 'approve') {
            const note = await dialog.request('ملاحظة الاعتماد (اختياري):', {title: 'اعتماد طلب', okLabel: 'اعتماد'}) || null;
            const j = await post(`/approvals/${id}/approve`, {note});
            await dialog.show(j.message || (j.success ? 'اعتُمد' : 'فشل'), j.success ? 'تم الاعتماد' : 'تعذّر الاعتماد');
        } else {
            const note = await dialog.request('سبب الرفض (5 أحرف على الأقل):', {title: 'رفض طلب', okLabel: 'رفض', danger: true});
            if (note === null) return;
            if (note.trim().length < 5) {
                await dialog.show('سبب الرفض إلزامي ويجب أن يتكوّن من 5 أحرف على الأقل.', 'بيانات ناقصة');
                return;
            }
            const j = await post(`/approvals/${id}/reject`, {note: note.trim()});
            await dialog.show(j.message || (j.success ? 'رُفض' : 'فشل'), j.success ? 'تم الرفض' : 'تعذّر الرفض');
        }
        loadApprovals();
    });

    // ---------- أمن داخلي ----------
    document.getElementById('btn-insider').onclick = loadInsider;
    async function loadInsider() {
        const j = await get('/insider/overview');
        if (!j.success) return;
        const m = j.meta;

        document.getElementById('insider-chain').innerHTML = m.audit_chain_ok === null ? '' : (m.audit_chain_ok
            ? '<div class="alert alert-success py-2">✓ سلسلة سجل التدقيق سليمة — لا عبث (فحص آخر 200 سجل)</div>'
            : '<div class="alert alert-danger py-2">✗ تحذير: سلسلة سجل التدقيق مكسورة — عبث محتمل! شغّل amial:audit-verify فوراً</div>');

        const act = (m.activity_today || []).map(a => `
            <tr><td>${esc(a.admin_name)}</td><td>${a.profile_views}</td><td>${a.distinct_customers}</td>
            <td>${a.searches}</td><td class="small">${esc(a.last_activity_at)}</td></tr>`).join('');
        document.getElementById('insider-activity').innerHTML =
            `<div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>الموظف</th><th>اطّلاعات</th><th>عملاء مختلفون</th><th>بحث</th><th>آخر نشاط</th></tr></thead>
            <tbody>${act || '<tr><td colspan="5" class="text-muted">لا نشاط اليوم</td></tr>'}</tbody></table></div>`;

        const typeLabels = {excessive_profile_views: 'إفراط اطّلاع على ملفات', excessive_searches: 'إفراط بحث',
                            after_hours_access: 'وصول خارج الدوام', rapid_sequential_access: 'وصول متسلسل سريع'};
        const alerts = (m.open_alerts || []).map(a => `
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><span class="badge bg-${a.severity === 'critical' ? 'danger' : 'warning text-dark'}">${esc(a.severity)}</span>
                ${esc(typeLabels[a.alert_type] || a.alert_type)} — ${esc(a.admin ? (a.admin.f_name + ' ' + (a.admin.l_name || '')) : a.admin_id)}
                <small class="text-muted">${esc(a.created_at)}</small></span>
                ${CAN_ACK_INSIDER ? `<button class="btn btn-sm btn-outline-secondary js-ack" data-id="${a.id}">مراجعة ✓</button>` : ''}
            </li>`).join('');
        document.getElementById('insider-alerts').innerHTML = alerts
            ? `<ul class="list-group">${alerts}</ul>`
            : '<div class="text-muted">لا تنبيهات مفتوحة</div>';
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-ack');
        if (!b) return;
        await post(`/insider/alerts/${b.dataset.id}/ack`, {});
        loadInsider();
    });

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

    // روابط لوحة التحكم تقود إلى الطابور نفسه لا إلى أول تبويب عشوائياً.
    // لا نقبل إلا تبويبات الشاشة الفعلية، ثم نحمل بيانات الطابور عند فتحه.
    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    const tabLoaders = {tickets: loadTickets, approvals: loadApprovals, insider: loadInsider, ops: loadOps};
    if (requestedTab && tabLoaders[requestedTab]) {
        const trigger = document.querySelector(`[data-bs-target="#tab-${requestedTab}"]`);
        if (trigger && window.bootstrap?.Tab) {
            window.bootstrap.Tab.getOrCreateInstance(trigger).show();
            tabLoaders[requestedTab]();
        }
    }
})();
</script>
@endsection
