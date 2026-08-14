@extends('layouts.admin.app')

{{--
    AMIAL-CUSTOMER-CENTER-001 — مركز العملاء (الفصل ٠٢).

    **الشرط الذي تفرضه الوثيقة حرفياً:** «يجب أن يستطيع موظف خدمة العملاء
    إدارة العميل **بالكامل من شاشة واحدة** دون الحاجة للانتقال بين أكثر من
    لوحة».

    وكان العميل موزّعاً على ثلاث شاشات — قائمةٌ في «مركز العملاء»، وملفٌّ في
    «مركز الدعم»، وكشفٌ في «كشف المعاملات». وموظّفٌ على الهاتف مع عميلٍ يقفز
    بينها ويفقد سياقه في كلّ قفزة، ويعيد على العميل السؤال نفسه.

    **والتبويبات تُحمَّل عند الضغط لا عند الفتح:** الوثيقة تشترط فتح الملفّ
    في ≤ ثانية، وتحميلُ العشرة معاً يجعل الزمن مجموعَ أبطئها. فيتحقّق الشرط
    بعدم تنفيذ الاستعلام أصلاً حتى يُطلب.
--}}

@section('title', 'مركز العملاء')

@section('content')
<div class="content container-fluid" id="cc" data-testid="customer-center">

    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="tio-user-outlined text-primary" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">مركز العملاء</h2>
        <span class="badge badge-soft-secondary ms-auto">شاشة واحدة</span>
    </div>

    {{-- البحث: عشرة مفاتيح تعدّها الوثيقة. وكلّ بحثٍ يُسجَّل. --}}
    <div class="card p-3 mb-3">
        <div class="input-group">
            <input type="text" id="cc-q" class="form-control form-control-lg"
                   placeholder="هاتف / اسم / بريد / رقم حساب / رقم عملية…" data-testid="cc-search">
            <button class="btn btn-primary" id="cc-btn-search">بحث</button>
        </div>
        <div id="cc-results" class="mt-2"></div>
    </div>

    <div id="cc-profile" data-testid="cc-profile"></div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/amial/customer') }}';
    const CSRF = '{{ csrf_token() }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const num = n => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const dt = s => esc(String(s || '').slice(0, 16).replace('T', ' '));

    let current = null;
    const loaded = {};

    async function get(p) { return (await fetch(BASE + p, {headers: {'Accept': 'application/json'}})).json(); }
    async function post(p, b) {
        return (await fetch(BASE + p, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(b || {}),
        })).json();
    }

    // ---------- البحث ----------
    document.getElementById('cc-btn-search').onclick = doSearch;
    document.getElementById('cc-q').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });

    async function doSearch() {
        const q = document.getElementById('cc-q').value.trim();
        if (q.length < 2) return;
        const box = document.getElementById('cc-results');
        box.innerHTML = '<div class="text-muted small">جارٍ البحث…</div>';
        const j = await get('/search?q=' + encodeURIComponent(q));
        if (!j.success) { box.innerHTML = ''; return; }

        const items = j.meta.items || [];
        box.innerHTML = items.length ? '<div class="list-group mt-2">' + items.map(u => `
            <button class="list-group-item list-group-item-action d-flex justify-content-between js-cc-open"
                    data-id="${u.id}" data-testid="cc-result-${u.id}">
                <span>${esc(u.name)}
                    ${u.is_frozen ? '<span class="badge bg-danger">مجمَّد</span>' : ''}
                    ${u.is_kyc_verified ? '<span class="badge bg-success">هوية ✓</span>' : '<span class="badge bg-warning text-dark">هوية ✗</span>'}
                </span>
                <span class="text-muted font-monospace">#${u.id} • ${esc(u.phone)}</span>
            </button>`).join('') + '</div>'
            : '<div class="alert alert-secondary mt-2 mb-0">لا نتائج</div>';
    }

    document.addEventListener('click', e => {
        const b = e.target.closest('.js-cc-open');
        if (b) openCustomer(parseInt(b.dataset.id, 10));
    });

    // ---------- الملفّ ----------
    const TABS = [
        ['overview', '📋 نظرة عامة'], ['wallets', '💰 المحافظ'], ['transactions', '💳 العمليات'],
        ['devices', '📱 الأجهزة'], ['authentication', '🔐 المصادقة'], ['kyc', '🪪 الهوية'],
        ['risk', '⚠️ المخاطر'], ['support', '🎫 الدعم'], ['notifications', '🔔 الإشعارات'],
        ['audit', '📜 التدقيق'],
    ];

    async function openCustomer(id) {
        current = id;
        for (const k in loaded) delete loaded[k];

        document.getElementById('cc-profile').innerHTML = `
            <div id="cc-head" class="card p-3 mb-3"><div class="text-muted">جارٍ التحميل…</div></div>
            <ul class="nav nav-tabs mb-3">${TABS.map((t, i) => `
                <li class="nav-item"><button class="nav-link ${i === 0 ? 'active' : ''} js-cc-tab"
                    data-tab="${t[0]}" data-testid="cc-tab-${t[0]}">${t[1]}</button></li>`).join('')}
            </ul>
            <div id="cc-tab-body"></div>`;

        loadTab('overview');
    }

    document.addEventListener('click', e => {
        const b = e.target.closest('.js-cc-tab');
        if (!b) return;
        document.querySelectorAll('.js-cc-tab').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        loadTab(b.dataset.tab);
    });

    async function loadTab(tab) {
        const body = document.getElementById('cc-tab-body');
        body.innerHTML = '<div class="text-muted p-3">جارٍ التحميل…</div>';
        const j = await get(`/${current}/tab/${tab}`);
        if (!j.success) { body.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }
        loaded[tab] = j.meta;
        RENDER[tab](j.meta, body);
    }

    // ---------- المصيّرات ----------
    const card = (t, v, s, cls) => `
        <div class="col-lg-3 col-md-4 col-6"><div class="card p-3 h-100 ${cls || ''}">
            <div class="small text-muted">${t}</div><div class="fs-5 fw-bold">${v}</div>
            ${s ? `<div class="small text-muted">${s}</div>` : ''}</div></div>`;

    const table = (head, rows, empty, tid) => `
        <div class="table-responsive"><table class="table table-sm" ${tid ? `data-testid="${tid}"` : ''}>
            <thead class="thead-light"><tr>${head.map(h => `<th>${h}</th>`).join('')}</tr></thead>
            <tbody>${rows || `<tr><td colspan="${head.length}" class="text-muted text-center py-3">${empty}</td></tr>`}</tbody>
        </table></div>`;

    const RENDER = {
        overview(m, body) {
            const p = m.profile, s = m.status, w = m.wallet, l = m.limits;

            // كلّ الأسباب لا الحالة الظاهرة وحدها: من يرى «مجمَّد» ولا يعرف
            // أنّ هويّته مرفوضة أيضاً سيرفع التجميد ثمّ يتفاجأ.
            document.getElementById('cc-head').innerHTML = `
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h4 class="mb-1">${esc(p.name)}
                            <span class="badge bg-${s.severity}">${esc(s.label)}</span></h4>
                        <div class="text-muted font-monospace">#${p.id} • ${esc(p.phone)} • ${esc(p.email)}</div>
                        <div class="small text-muted">${esc(p.type)} • ${esc(p.zone_code)} • مسجَّل ${dt(p.registered_at)}</div>
                    </div>
                    <button class="btn btn-outline-primary btn-sm" id="cc-actions-btn">⚡ الإجراءات</button>
                </div>
                ${s.reasons.length ? `<div class="alert alert-${s.severity} py-2 small mt-2 mb-0">
                    ${s.reasons.map(r => '• ' + esc(r)).join('<br>')}</div>` : ''}`;

            document.getElementById('cc-actions-btn').onclick = showActions;

            body.innerHTML = `
                <div class="row g-3 mb-3">
                    ${card('الرصيد الحاليّ', num(w.current_balance))}
                    ${card('المتاح فعلاً', num(w.available_balance), 'محجوز ' + num(w.held_balance),
                           Number(w.held_balance) > 0 ? 'border-warning' : '')}
                    ${card('درجة الخطر', esc(m.risk.score), esc(m.risk.level),
                           ['high','very_high','critical'].includes(m.risk.level) ? 'border-danger' : '')}
                    ${card('فئة الهوية', m.kyc.tier, m.kyc.is_verified ? 'موثَّقة' : 'غير موثَّقة',
                           m.kyc.is_verified ? '' : 'border-warning')}
                    ${card('تذاكر مفتوحة', m.counters.open_tickets)}
                    ${card('تحقيقات مفتوحة', m.counters.open_investigations, '',
                           m.counters.open_investigations > 0 ? 'border-danger' : '')}
                    ${card('مستندات تنتظر', m.counters.pending_kyc_docs)}
                    ${card('أجهزة محظورة', m.counters.blocked_devices)}
                </div>

                <div class="row g-3">
                    <div class="col-lg-6"><div class="card p-3">
                        <h6>الحدود <span class="badge bg-${l.has_override ? 'warning text-dark' : 'secondary'}">${esc(l.source)}</span></h6>
                        ${table(['الحدّ', 'القيمة'], [
                            ['أقصى رصيد', l.max_balance], ['أقصى عملية', l.max_single_transaction],
                            ['يوميّ', l.max_daily_total], ['شهريّ', l.max_monthly_total],
                        ].map(r => `<tr><td>${r[0]}</td><td class="text-end">${num(r[1])}</td></tr>`).join(''), '')}
                    </div></div>

                    <div class="col-lg-6"><div class="card p-3">
                        <h6>الملاحظات</h6>
                        <div style="max-height:260px;overflow:auto">${m.notes.length ? m.notes.map(n => `
                            <div class="border-bottom py-2">
                                ${n.is_pinned ? '<span class="badge bg-warning text-dark">مثبّتة</span> ' : ''}
                                <span class="small">${esc(n.body)}</span>
                                <div class="small text-muted">${esc(n.author)} — ${dt(n.created_at)}</div>
                            </div>`).join('') : '<div class="text-muted small">لا ملاحظات</div>'}</div>
                    </div></div>
                </div>`;
        },

        wallets(m, body) {
            body.innerHTML = table(['المحفظة', 'الرصيد', 'محجوز', 'معلَّق', 'العملة', 'الحالة'],
                m.wallets.map(w => `<tr><td>${esc(w.name)}</td><td class="text-end fw-bold">${num(w.balance)}</td>
                    <td class="text-end">${num(w.reserved)}</td><td class="text-end">${num(w.pending)}</td>
                    <td>${esc(w.currency)}</td><td>${esc(w.status)}</td></tr>`).join(''),
                'لا محافظ', 'cc-wallets');
        },

        transactions(m, body) {
            body.innerHTML = table(['المرجع', 'النوع', 'مدين', 'دائن', 'الرصيد', 'التاريخ'],
                m.items.map(t => `<tr><td class="font-monospace small">${esc(t.transaction_id)}</td>
                    <td>${esc(t.type)}</td><td class="text-end">${num(t.debit)}</td>
                    <td class="text-end">${num(t.credit)}</td><td class="text-end">${num(t.balance)}</td>
                    <td class="small">${dt(t.created_at)}</td></tr>`).join(''),
                'لا عمليات', 'cc-transactions');
        },

        devices(m, body) {
            body.innerHTML = table(['الجهاز', 'النظام', 'IP', 'آخر ظهور', 'الحالة'],
                m.devices.map(d => `<tr class="${d.is_blocked ? 'table-danger' : ''}">
                    <td>${esc(d.device_model)}<div class="small text-muted font-monospace">${esc(String(d.device_id).slice(0, 14))}…</div></td>
                    <td class="small">${esc(d.os)}${d.app_version ? ' • v' + esc(d.app_version) : ''}</td>
                    <td class="small font-monospace">${esc(d.ip_address)}</td>
                    <td class="small">${dt(d.last_seen_at)}</td>
                    <td>${d.is_blocked ? '<span class="badge bg-danger">محظور</span>'
                        : (d.is_active ? '<span class="badge bg-success">نشط</span>' : '<span class="badge bg-secondary">غير نشط</span>')}
                        ${d.is_trusted ? '<span class="badge bg-info">موثوق</span>' : ''}
                        ${d.block_reason ? `<div class="small text-muted">${esc(d.block_reason)}</div>` : ''}</td>
                    </tr>`).join(''), 'لا أجهزة', 'cc-devices');
        },

        authentication(m, body) {
            const list = (title, rows, cls) => `
                <div class="col-lg-6 mb-3"><div class="card p-3">
                    <h6 class="${cls || ''}">${title} <span class="badge bg-secondary">${rows.length}</span></h6>
                    <div style="max-height:220px;overflow:auto">${rows.length ? rows.map(e => `
                        <div class="border-bottom py-1 small">
                            <span class="badge bg-light text-dark">${esc(e.type)}</span>
                            ${esc(e.note)}
                            <div class="text-muted">${esc(e.ip)} — ${dt(e.at)}</div>
                        </div>`).join('') : '<div class="text-muted small">لا شيء</div>'}</div>
                </div></div>`;

            body.innerHTML = `
                <div class="row g-0">
                    ${list('تغييرات الهاتف', m.phone_changes, 'text-danger')}
                    ${list('تغييرات الرمز السرّي', m.pin_changes)}
                    ${list('محاولات فاشلة', m.failed_attempts, 'text-warning')}
                    ${list('أحداث أخرى', m.other)}
                </div>
                <div class="alert alert-secondary py-2 small">
                    الرمز ${m.pin.is_set ? 'معيَّن' : 'غير معيَّن'} •
                    ${m.pin.failed_attempts} محاولة فاشلة
                    ${m.pin.locked_until ? '• <strong>مقفول</strong>' : ''}
                </div>`;
        },

        kyc(m, body) {
            const c = m.completeness;
            body.innerHTML = `
                <div class="alert alert-${c.complete ? 'success' : 'warning'} py-2">
                    ${c.complete ? 'مستندات الفئة ' + c.tier + ' مكتملة'
                        : 'ينقص: ' + (c.missing || []).map(esc).join('، ')}
                </div>
                ${table(['المستند', 'الحالة', 'القراءة الآلية', 'ينتهي', 'المراجع', 'رُفع'],
                    m.documents.map(d => `<tr>
                        <td>${esc(d.doc_label)}</td>
                        <td><span class="badge bg-${d.status === 'approved' ? 'success' : (d.status === 'rejected' ? 'danger' : 'warning text-dark')}">${esc(d.status)}</span>
                            ${d.rejection_reason ? `<div class="small text-muted">${esc(d.rejection_reason)}</div>` : ''}</td>
                        <td class="small">${esc(d.ocr_status)}</td>
                        <td class="small">${esc(d.expires_at || '—')}</td>
                        <td class="small">${esc(d.reviewer || '—')}</td>
                        <td class="small">${dt(d.uploaded_at)}</td></tr>`).join(''),
                    'لا مستندات', 'cc-kyc')}`;
        },

        risk(m, body) {
            const p = m.profile;
            body.innerHTML = `
                <div class="row g-3 mb-3">
                    ${card('درجة الخطر', esc(p.score), esc(p.level),
                           ['high','very_high','critical'].includes(p.level) ? 'border-danger' : '')}
                    ${card('الاستثناء اليدويّ', esc(p.override), esc(p.override_reason || ''),
                           p.override === 'whitelist' ? 'border-warning' : '')}
                    ${card('العقوبات', esc(m.sanction_status),
                           '', m.sanction_status === 'blocked' ? 'border-danger' : '')}
                    ${card('تحقيقات', m.investigations.length)}
                </div>
                <div class="row g-3">
                    <div class="col-lg-6"><div class="card p-3"><h6>عمليات معلّقة</h6>
                        ${table(['المرجع', 'المبلغ', 'الخطر', 'الحالة'],
                            m.flagged.map(f => `<tr><td class="font-monospace small">${esc(String(f.flag_ulid).slice(0, 10))}…</td>
                            <td>${num(f.amount)}</td><td>${esc(f.risk_score)}</td><td class="small">${esc(f.status)}</td></tr>`).join(''),
                            'لا شيء')}</div></div>
                    <div class="col-lg-6"><div class="card p-3"><h6>القضايا</h6>
                        ${table(['القضية', 'الحالة', 'الأولوية', 'العمر'],
                            m.investigations.map(i => `<tr><td class="font-monospace small">${esc(i.case_number)}</td>
                            <td class="small">${esc(i.status)}</td><td class="small">${esc(i.priority)}</td>
                            <td class="small">${i.age_hours} س</td></tr>`).join(''), 'لا قضايا')}</div></div>
                </div>`;
        },

        support(m, body) {
            const directions = {outgoing: 'طلبه العميل', incoming: 'طُلب منه'};
            const statuses = {pending: 'بانتظار الموافقة', paid: 'مدفوع', declined: 'مرفوض', cancelled: 'ملغى', expired: 'منتهٍ'};
            body.innerHTML = `
                <div class="card p-3 mb-3"><h6>طلبات الأموال المرتبطة بالعميل</h6>
                    ${table(['الرمز', 'الاتجاه/الطرف', 'المبلغ', 'الحالة', 'رقم العملية', 'التاريخ'],
                        (m.payment_requests || []).map(r => `<tr>
                            <td class="font-monospace small" title="${esc(r.request_ulid)}">${esc(r.short_code)}</td>
                            <td class="small"><span class="badge bg-light text-dark">${esc(directions[r.direction] || r.direction)}</span>
                                ${esc(r.counterparty)}<div class="text-muted font-monospace">${esc(r.counterparty_phone)}</div></td>
                            <td class="text-end fw-bold">${num(r.amount)}</td>
                            <td>${esc(statuses[r.status] || r.status)}</td>
                            <td class="font-monospace small">${esc(r.paid_transaction_id || '—')}</td>
                            <td class="small">${dt(r.paid_at || r.created_at)}</td></tr>`).join(''),
                        'لا طلبات أموال', 'cc-payment-requests')}
                </div>
                <div class="card p-3"><h6>تذاكر الدعم</h6>
                    ${table(['الرقم', 'الموضوع', 'الحالة', 'الأولوية', 'آخر تحديث'],
                        m.tickets.map(t => `<tr><td class="font-monospace small">${esc(t.ticket_number)}</td>
                            <td>${esc(t.subject)}</td><td>${esc(t.status)}</td><td>${esc(t.priority)}</td>
                            <td class="small">${dt(t.updated_at)}</td></tr>`).join(''), 'لا تذاكر', 'cc-support')}
                </div>`;
        },

        notifications(m, body) {
            // «أُرسل» و«قُرئ» عمودان لا واحد: شكوى «لم يصلني إشعار» تُحسم
            // بالفرق بينهما.
            body.innerHTML = table(['النوع', 'العنوان', 'أُرسل', 'قُرئ'],
                m.items.map(n => `<tr class="${n.is_read ? '' : 'table-light'}">
                    <td class="small font-monospace">${esc(n.type)}</td>
                    <td class="small">${esc(n.title)}<div class="text-muted">${esc(n.body)}</div></td>
                    <td class="small">${dt(n.sent_at)}</td>
                    <td class="small">${n.is_read ? dt(n.read_at) : '<span class="badge bg-warning text-dark">لم يُقرأ</span>'}</td>
                    </tr>`).join(''), 'لا إشعارات', 'cc-notifications');
        },

        audit(m, body) {
            body.innerHTML = table(['المصدر', 'الحدث', 'التفصيل', 'المنفِّذ', 'التاريخ'],
                m.items.map(a => `<tr>
                    <td><span class="badge bg-${a.severity === 'critical' ? 'danger' : (a.severity === 'warning' ? 'warning text-dark' : 'secondary')}">${esc(a.source)}</span></td>
                    <td class="small font-monospace">${esc(a.action)}</td>
                    <td class="small">${esc(a.note)}</td>
                    <td class="small">${esc(a.actor)}</td>
                    <td class="small">${dt(a.at)}</td></tr>`).join(''), 'لا سجلّ', 'cc-audit');
        },
    };

    // ---------- الإجراءات ----------
    const ACTIONS = [
        ['freeze', 'تجميد الحساب', 'danger'], ['unfreeze', 'إلغاء التجميد', 'success'],
        ['suspend', 'إيقاف مؤقت', 'warning'], ['activate', 'تفعيل الحساب', 'success'],
        ['reset_pin', 'إعادة تعيين PIN', 'warning'], ['revoke_sessions', 'إنهاء كل الجلسات', 'secondary'],
        ['require_kyc', 'طلب تحديث الهوية', 'info'], ['update_limits', 'تعديل الحدود', 'primary'],
        ['add_note', 'إضافة ملاحظة', 'secondary'], ['escalate_risk', 'تحويل إلى المخاطر', 'danger'],
        ['close', 'إغلاق الحساب', 'dark'], ['mark_deceased', 'تعليم كمتوفّى', 'dark'],
    ];

    function showActions() {
        const html = ACTIONS.map(a => `
            <button class="btn btn-sm btn-outline-${a[2]} m-1 js-cc-act" data-act="${a[0]}"
                    data-label="${a[1]}" data-testid="cc-act-${a[0]}">${a[1]}</button>`).join('');

        document.getElementById('cc-tab-body').insertAdjacentHTML('afterbegin', `
            <div class="card p-3 mb-3" id="cc-actions">
                <div class="d-flex align-items-center mb-2">
                    <h6 class="mb-0">الإجراءات السريعة</h6>
                    <span class="small text-muted ms-auto">
                        السبب إلزاميّ في كلّ إجراء — يُراجعه بعد شهرٍ من لم يحضر المكالمة.
                    </span>
                </div>
                <div>${html}</div>
            </div>`);
    }

    document.addEventListener('click', async e => {
        const b = e.target.closest('.js-cc-act');
        if (!b) return;
        const act = b.dataset.act;

        const reason = prompt(`سبب «${b.dataset.label}» (١٠ أحرف على الأقل — يُسجَّل في التدقيق):`);
        if (!reason || reason.trim().length < 10) { alert('السبب إلزامي (١٠ أحرف على الأقل)'); return; }

        let payload = {};

        if (act === 'update_limits') {
            payload = {
                max_single_transaction: prompt('أقصى عملية:') || undefined,
                max_daily_total: prompt('الحدّ اليوميّ:') || undefined,
                max_monthly_total: prompt('الحدّ الشهريّ:') || undefined,
            };
        } else if (act === 'add_note') {
            payload = {body: reason, pin: confirm('تثبيت الملاحظة أعلى الملفّ؟')};
        } else if (act === 'close' || act === 'mark_deceased') {
            if (!confirm(`«${b.dataset.label}» إجراءٌ لا يُتراجع عنه من هذه الشاشة. متابعة؟`)) return;
        }

        const j = await post(`/${current}/action`, {action: act, reason: reason.trim(), payload});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) loadTab('overview');
    });
})();
</script>
@endsection
