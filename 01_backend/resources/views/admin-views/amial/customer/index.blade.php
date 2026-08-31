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

    {{-- مفاتيح البحث الفعلية فقط؛ كلّ بحث يسجّل بلا حفظ النص الحساس خاماً. --}}
    <div class="card p-3 mb-3">
        <div class="input-group">
            <input type="text" id="cc-q" class="form-control form-control-lg"
                   placeholder="هاتف / اسم / بريد / رقم حساب / معرّف محفظة / رقم عملية…" data-testid="cc-search">
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
    const ALLOWED_TABS = @json($tabs);
    const ALLOWED_ACTIONS = @json($actions);
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    // لا نحول الريال إلى Number: JavaScript يفقد الدقة بعد 2^53، والمال لا
    // يُقرّب في المتصفح. التنسيق نصّي، والحساب يبقى في محرك المال/الخادم.
    const money = value => {
        if (value === null || value === undefined || value === '') return 'غير متاح';
        let raw = String(value).trim();
        const sign = raw.startsWith('-') ? '-' : '';
        raw = raw.replace(/^[+-]/, '');
        if (!/^\d+(?:\.\d+)?$/.test(raw)) return esc(value);
        let [whole, fraction = ''] = raw.split('.');
        whole = whole.replace(/^0+(?=\d)/, '') || '0';
        whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        fraction = fraction.replace(/0+$/, '');
        return sign + whole + (fraction ? '.' + fraction : '');
    };
    const dt = s => esc(String(s || '').slice(0, 16).replace('T', ' '));

    let current = null;
    let currentOverview = null;
    const loaded = {};
    let searchSequence = 0;
    let customerSequence = 0;
    // يصل زر «تعديل» من القائمة القديمة بهذا المعرف. نفتح الملف مباشرة
    // بدلاً من ترك الموظف يبحث ثانية أو إرساله إلى قالب محذوف.
    const requestedCustomer = Number(new URLSearchParams(window.location.search).get('open'));

    async function get(p) {
        const response = await fetch(BASE + p, {headers: {'Accept': 'application/json'}});
        const body = await response.json().catch(() => ({}));
        if (!response.ok && body.success === undefined) throw new Error('تعذّر الاتصال بالخادم');
        return body;
    }
    async function post(p, b) {
        const response = await fetch(BASE + p, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(b || {}),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok && body.success === undefined) throw new Error('تعذّر الاتصال بالخادم');
        return body;
    }

    // ---------- البحث ----------
    document.getElementById('cc-btn-search').onclick = doSearch;
    document.getElementById('cc-q').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });

    async function doSearch() {
        const q = document.getElementById('cc-q').value.trim();
        if (q.length < 2) return;
        const box = document.getElementById('cc-results');
        const sequence = ++searchSequence;
        box.innerHTML = '<div class="text-muted small">جارٍ البحث…</div>';
        let j;
        try { j = await get('/search?q=' + encodeURIComponent(q)); }
        catch (error) { if (sequence === searchSequence) box.innerHTML = '<div class="alert alert-warning mt-2 mb-0">تعذّر البحث، حاول مجدداً.</div>'; return; }
        if (sequence !== searchSequence) return;
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning mt-2 mb-0">${esc(j.message || 'تعذّر البحث')}</div>`; return; }

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
    const ALL_TABS = [
        ['overview', '📋 نظرة عامة'], ['wallets', '💰 المحافظ'], ['transactions', '💳 العمليات'],
        ['devices', '📱 الأجهزة'], ['authentication', '🔐 المصادقة'], ['kyc', '🪪 الهوية'],
        ['risk', '⚠️ المخاطر'], ['support', '🎫 الدعم'], ['notifications', '🔔 الإشعارات'],
        ['audit', '📜 التدقيق'],
    ];
    const TABS = ALL_TABS.filter(([code]) => ALLOWED_TABS.includes(code));

    async function openCustomer(id) {
        current = id;
        const sequence = ++customerSequence;
        for (const k in loaded) delete loaded[k];

        document.getElementById('cc-profile').innerHTML = `
            <div id="cc-head" class="card p-3 mb-3"><div class="text-muted">جارٍ التحميل…</div></div>
            <ul class="nav nav-tabs mb-3">${TABS.map((t, i) => `
                <li class="nav-item"><button class="nav-link ${i === 0 ? 'active' : ''} js-cc-tab"
                    data-tab="${t[0]}" data-testid="cc-tab-${t[0]}">${t[1]}</button></li>`).join('')}
            </ul>
            <div id="cc-tab-body"></div>`;

        if (TABS.length) loadTab(TABS[0][0], sequence);
    }

    document.addEventListener('click', e => {
        const b = e.target.closest('.js-cc-tab');
        if (!b) return;
        document.querySelectorAll('.js-cc-tab').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        loadTab(b.dataset.tab, customerSequence);
    });

    async function loadTab(tab, sequence = customerSequence) {
        const body = document.getElementById('cc-tab-body');
        body.innerHTML = '<div class="text-muted p-3">جارٍ التحميل…</div>';
        const customerId = current;
        let j;
        try { j = await get(`/${customerId}/tab/${tab}`); }
        catch (error) { if (sequence === customerSequence && customerId === current) body.innerHTML = '<div class="alert alert-warning">تعذّر تحميل البيانات. حدّث التبويب وحاول مجدداً.</div>'; return; }
        if (sequence !== customerSequence || customerId !== current) return;
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
            currentOverview = m;
            const p = m.profile, s = m.status, w = m.wallet, l = m.limits, f = m.financial_truth;

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
                    ${card('الرصيد التشغيلي', money(w.current_balance),
                        f.state === 'reconciled' ? 'مطابق للدفتر' : (f.reason || 'يتطلب تحققاً'),
                        f.state === 'reconciled' ? 'border-success' : 'border-danger')}
                    ${card('المتاح فعلاً', money(w.available_balance), 'محجوز ' + money(w.held_balance),
                           String(w.held_balance || '0') !== '0' ? 'border-warning' : '')}
                    ${card('درجة الخطر',
                           m.risk.state === 'measured' ? esc(m.risk.score) : '—',
                           m.risk.state === 'measured' ? esc(m.risk.level) : 'لم يُحتسب بعد',
                           m.risk.state === 'unassessed' ? 'border-warning'
                               : (['high','very_high','critical'].includes(m.risk.level) ? 'border-danger' : ''))}
                    ${card('حالة الهوية', esc(m.kyc.reconciliation.label), esc(m.kyc.reconciliation.description),
                           m.kyc.reconciliation.severity === 'success' ? 'border-success'
                               : (m.kyc.reconciliation.severity === 'danger' ? 'border-danger' : 'border-warning'))}
                    ${card('تذاكر مفتوحة', m.counters.open_tickets)}
                    ${card('تحقيقات مفتوحة', m.counters.open_investigations, '',
                           m.counters.open_investigations > 0 ? 'border-danger' : '')}
                    ${card('مستندات تنتظر', m.counters.pending_kyc_docs)}
                    ${card('أجهزة محظورة', m.counters.blocked_devices)}
                </div>

                <div class="row g-3">
                    <div class="col-lg-6"><div class="card p-3">
                        <h6>الحدود <span class="badge bg-${l.has_override ? 'warning text-dark' : 'secondary'}">${esc(l.source)}</span></h6>
                        ${l.state === 'configured' ? table(['الحدّ', 'القيمة'], [
                            ['أقصى رصيد', l.max_balance], ['أقصى عملية', l.max_single_transaction],
                            ['يوميّ', l.max_daily_total], ['شهريّ', l.max_monthly_total],
                        ].map(r => `<tr><td>${r[0]}</td><td class="text-end">${money(r[1])}</td></tr>`).join(''), '')
                        : '<div class="alert alert-warning mb-0 small">لا توجد سياسة حدود نافذة لهذا العميل؛ لا يُفسَّر ذلك كحدّ صفري.</div>'}
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
                m.wallets.map(w => `<tr><td>${esc(w.name)}</td><td class="text-end fw-bold">${money(w.balance)}</td>
                    <td class="text-end">${money(w.reserved)}</td><td class="text-end">${money(w.pending)}</td>
                    <td>${esc(w.currency)}</td><td>${esc(w.status)}</td></tr>`).join(''),
                'لا محافظ', 'cc-wallets');
        },

        transactions(m, body) {
            body.innerHTML = table(['المرجع', 'النوع', 'مدين', 'دائن', 'الرصيد', 'التاريخ'],
                m.items.map(t => `<tr><td class="font-monospace small">${esc(t.transaction_id)}</td>
                    <td>${esc(t.type)}</td><td class="text-end">${money(t.debit)}</td>
                    <td class="text-end">${money(t.credit)}</td><td class="text-end">${money(t.balance)}</td>
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
            const r = m.reconciliation;
            body.innerHTML = `
                <div class="alert alert-${esc(r.severity)} py-2" data-testid="cc-kyc-reconciliation">
                    <strong>${esc(r.label)}</strong>
                    <div class="small mt-1">${esc(r.description)}</div>
                    <div class="small mt-2">
                        فئة الحساب: ${esc(m.tier)} • فئة ملف المستندات: ${esc(m.document_target_tier)}<br>
                        ${c.complete ? 'مستندات الفئة ' + esc(c.tier) + ' مكتملة'
                            : 'المطلوب للاستكمال: ' + (c.missing || []).map(esc).join('، ')}
                    </div>
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
                    'لا مستندات', 'cc-kyc')}
                ${card('ملفات التسجيل المؤرشفة',
                    table(['المرجع', 'المصدر', 'الحالة', 'ورقي', 'أنشئ'],
                        (m.registration_dossiers || []).map(d => `<tr><td class="font-monospace">${esc(d.reference)}</td><td>${esc(d.source)}</td><td>${esc(d.state)}</td><td>${d.has_paper_form ? 'نعم' : '—'}</td><td>${dt(d.created_at)}</td></tr>`),
                        'لا ملف تسجيل مرتبط بهذا العميل', 'cc-registration-dossiers'),
                    'تُفتح النسخة الكاملة والطباعة من «ملفات التسجيل والأرشفة» بحسب صلاحيتك.')}`;
        },

        risk(m, body) {
            const p = m.profile;
            body.innerHTML = `
                <div class="row g-3 mb-3">
                    ${card('درجة الخطر',
                           p.state === 'measured' ? esc(p.score) : '—',
                           p.state === 'measured' ? esc(p.level) : 'لم يُحتسب بعد',
                           p.state === 'unassessed' ? 'border-warning'
                               : (['high','very_high','critical'].includes(p.level) ? 'border-danger' : ''))}
                    ${card('الاستثناء اليدويّ', esc(p.override), esc(p.override_reason || ''),
                           p.override === 'whitelist' ? 'border-warning' : '')}
                    ${(() => { const labels = {not_screened: 'لم يُجرَ فحص موثّق', clear: 'لا تطابق موثّق', flagged: 'تطابق محتمل', blocked: 'تطابق مؤكد'};
                        return card('العقوبات', esc(labels[m.sanction_status] || 'حالة غير موثقة'),
                            m.sanction_status === 'not_screened' ? 'لا نتيجة فحص قابلة للإثبات' : '',
                            m.sanction_status === 'blocked' ? 'border-danger' : (m.sanction_status === 'not_screened' ? 'border-warning' : '')); })()}
                    ${card('تحقيقات', m.investigations.length)}
                </div>
                <div class="row g-3">
                    <div class="col-lg-6"><div class="card p-3"><h6>عمليات معلّقة</h6>
                        ${table(['المرجع', 'المبلغ', 'الخطر', 'الحالة'],
                            m.flagged.map(f => `<tr><td class="font-monospace small">${esc(String(f.flag_ulid).slice(0, 10))}…</td>
                            <td>${money(f.amount)}</td><td>${esc(f.risk_score)}</td><td class="small">${esc(f.status)}</td></tr>`).join(''),
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
                            <td class="text-end fw-bold">${money(r.amount)}</td>
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
    const ACTION_STYLE = {
        freeze: 'danger', unfreeze: 'success', suspend: 'warning', activate: 'success',
        reset_pin: 'warning', revoke_sessions: 'secondary', require_kyc: 'info',
        update_limits: 'primary', add_note: 'secondary', escalate_risk: 'danger',
        close: 'dark', mark_deceased: 'dark',
    };

    function showActions() {
        if (!ALLOWED_ACTIONS.length || !currentOverview) return;
        const status = currentOverview.status || {};
        const reasons = status.reasons || [];
        const lifecycle = status.status || '';
        const frozen = reasons.some(r => String(r).includes('مجمَّد'));
        const terminal = ['CLOSED', 'DECEASED'].includes(lifecycle);
        const actions = ALLOWED_ACTIONS.filter(a => {
            if (terminal) return a.code === 'add_note';
            if (a.code === 'freeze') return !frozen;
            if (a.code === 'unfreeze') return frozen;
            if (a.code === 'activate') return ['SUSPENDED', 'INACTIVE'].includes(lifecycle);
            if (a.code === 'suspend') return lifecycle !== 'SUSPENDED';
            if (a.code === 'require_kyc') return !currentOverview.kyc?.update_required;
            if (a.code === 'escalate_risk') return !(currentOverview.counters?.open_investigations > 0);
            return true;
        });
        if (!actions.length) return;
        document.getElementById('cc-actions')?.remove();
        const html = actions.map(a => `
            <button class="btn btn-sm btn-outline-${ACTION_STYLE[a.code] || 'secondary'} m-1 js-cc-act" data-act="${a.code}"
                    data-label="${esc(a.label)}" data-testid="cc-act-${a.code}">${esc(a.label)}</button>`).join('');

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

        // نافذة أميال نفسها، لا prompt/confirm/alert التابعة للمتصفح. لها ×
        // وEsc وتُرجع Promise كي لا يُنفذ الفعل قبل أن يكتب الموظف سببه.
        const dialog = window.amialDialog;
        if (!dialog) return;
        const reason = await dialog.request(
            `سبب «${b.dataset.label}» (١٠ أحرف على الأقل — يُسجَّل في التدقيق):`,
            {title: 'تأكيد إجراء على العميل', okLabel: 'متابعة', danger: ['close', 'mark_deceased'].includes(act)},
        );
        if (reason === null) return;
        if (reason.trim().length < 10) {
            await dialog.show('السبب إلزامي ويجب أن يتكوّن من ١٠ أحرف على الأقل.', 'بيانات ناقصة');
            return;
        }

        let payload = {};

        if (act === 'update_limits') {
            const single = await dialog.request('أقصى عملية (اتركه فارغاً دون تغيير):', {title: 'تعديل الحدود', okLabel: 'التالي'});
            if (single === null) return;
            const daily = await dialog.request('الحد اليومي (اتركه فارغاً دون تغيير):', {title: 'تعديل الحدود', okLabel: 'التالي'});
            if (daily === null) return;
            const monthly = await dialog.request('الحد الشهري (اتركه فارغاً دون تغيير):', {title: 'تعديل الحدود', okLabel: 'حفظ'});
            if (monthly === null) return;
            payload = {
                max_single_transaction: single || undefined,
                max_daily_total: daily || undefined,
                max_monthly_total: monthly || undefined,
            };
        } else if (act === 'add_note') {
            const pin = await dialog.ask('هل تريد تثبيت الملاحظة أعلى الملفّ؟', {title: 'إضافة ملاحظة', okLabel: 'تثبيت'});
            payload = {body: reason, pin};
        } else if (act === 'close' || act === 'mark_deceased') {
            const confirmed = await dialog.ask(
                `«${b.dataset.label}» إجراءٌ لا يُتراجع عنه من هذه الشاشة. متابعة؟`,
                {title: 'تأكيد نهائي', okLabel: 'نعم، متابعة', danger: true},
            );
            if (!confirmed) return;
        }

        b.disabled = true;
        b.dataset.original = b.textContent;
        b.textContent = 'جارٍ الحفظ…';
        try {
            // نفس المفتاح يبقى مع هذا التنفيذ حتى لا يحوّل retry الشبكة أو
            // النقرتين المتقاربتين إلى ملاحظتين/قضيتين/طلبات اعتماد متعددة.
            const key = (window.crypto?.randomUUID?.() || (`cc-${Date.now()}-${Math.random().toString(36).slice(2)}`));
            const j = await post(`/${current}/action`, {
                action: act, reason: reason.trim(), payload, idempotency_key: key,
            });
            await dialog.show(j.message || (j.success ? 'تم' : 'فشل'), j.success ? 'تم الإجراء' : 'تعذّر الإجراء');
            if (j.success) loadTab('overview');
        } catch (error) {
            await dialog.show('تعذّر الاتصال بالخادم. لم نؤكد تنفيذ الإجراء؛ راجع سجل التدقيق قبل إعادة المحاولة.', 'تعذّر الإجراء');
        } finally {
            b.disabled = false;
            b.textContent = b.dataset.original || b.textContent;
        }
    });
    if (Number.isInteger(requestedCustomer) && requestedCustomer > 0) {
        openCustomer(requestedCustomer);
    }
})();
</script>
@endsection
