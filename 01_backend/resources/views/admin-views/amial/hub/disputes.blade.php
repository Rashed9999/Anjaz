@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — لوحة النزاعات (الدفع الآمن).
     واجهة حقيقية فوق AdminSafePaymentController الموجود: قائمة المدفوعات
     المتنازَع عليها، تفاصيل كل نزاع بأحداثه، وقرارات الحسم الثلاثة
     (إفراج للبائع / إعادة للمشتري / تسوية جزئية) — كلها تحرّك المال فعلاً. --}}

@section('title', 'لوحة النزاعات — الدفع الآمن')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">لوحة النزاعات — الدفع الآمن</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>

    <div class="card stat-card">
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <select id="status-filter" class="form-select" style="max-width:240px">
                <option value="disputed_open">النزاعات المفتوحة (تحتاج قراراً)</option>
                <option value="disputed">كل المتنازَع عليها</option>
                <option value="">كل مدفوعات الدفع الآمن</option>
                <option value="funded">مجمّدة (سارية)</option>
                <option value="in_delivery">قيد التسليم</option>
                <option value="delivered">بانتظار تأكيد المشتري</option>
                <option value="released_to_seller">مُفرَج عنها</option>
                <option value="refunded_to_buyer">مُعادة للمشتري</option>
                <option value="partially_refunded">تسوية جزئية</option>
                <option value="pending_seller_acceptance">بانتظار قبول البائع</option>
            </select>
            <input id="search-filter" class="form-control" style="max-width:260px"
                   placeholder="ابحث بالمرجع أو الاسم أو الجوال">
            <input type="date" id="date-from" class="form-control" style="max-width:155px"
                   title="من تاريخ" aria-label="من تاريخ">
            <input type="date" id="date-to" class="form-control" style="max-width:155px"
                   title="إلى تاريخ" aria-label="إلى تاريخ">
            <button class="btn btn-outline-primary" id="search-button" type="button">بحث</button>
            <button class="btn btn-outline-success" id="export-csv" type="button">تصدير CSV</button>
            <span class="text-muted small ms-auto" id="page-info"></span>
        </div>
        <div class="card-body border-bottom py-2 d-flex gap-3 flex-wrap small">
            <span>نزاعات مفتوحة: <b id="open-count">—</b></span>
            <span>أموال معلّقة: <b id="open-held">—</b> ر.ي</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>المرجع</th><th>المشتري</th><th>البائع</th><th>المبلغ</th>
                    <th>الحالة</th><th>تاريخ النزاع</th><th></th>
                </tr></thead>
                <tbody id="list-tbody">
                    <tr><td colspan="7" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
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

{{-- Modal: تفاصيل النزاع + الحسم --}}
<div class="modal fade" id="modal-dispute" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="d-title">تفاصيل النزاع</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div id="d-four-eyes" class="mb-3 d-none"></div>
            <div id="d-info" class="mb-3"></div>

            {{-- AMIAL-SAFEPAY-EVIDENCE-001: الأدلّة قبل القرار لا بعده --}}
            <div class="fw-bold mb-2">الأدلّة المرفوعة</div>
            <div id="d-evidence" class="mb-3"></div>

            <div class="fw-bold mb-2">سجلّ الأحداث</div>
            <ul class="list-group mb-3" id="d-events" style="max-height:200px;overflow-y:auto"></ul>

            {{-- AMIAL-SAFEPAY-AUDIT-001: السجلّ الذي لا يُحذف، معروضاً --}}
            <div class="fw-bold mb-2">سجلّ التدقيق
                <span class="badge bg-dark">لا يُحذف ولا يُعدَّل</span>
                <a class="small ms-2" id="d-audit-link" href="#" target="_blank" rel="noopener">السجلّ الكامل ↗</a>
            </div>
            <ul class="list-group mb-3" id="d-audit" style="max-height:180px;overflow-y:auto"></ul>

            <div class="card p-3 bg-light border" id="d-resolution-card">
                <div class="fw-bold mb-2">قرار الحسم</div>
                <div class="alert alert-secondary py-2 small mb-2">
                    قرارك يُقيَّد باسمك ووقته وببصمات الأدلّة المعروضة أعلاه في سلسلة
                    تدقيق مترابطة — أي حذف أو تعديل لاحق يكسر السلسلة ويُكشف.
                </div>
                <div class="mb-2">
                    <label class="form-label">سبب القرار — 10 أحرف فأكثر (يُسجَّل ويُبلَّغ للطرفين)</label>
                    <input class="form-control" id="d-reason" placeholder="مثال: البضاعة سُلّمت حسب الاتفاق والصور المرفقة…">
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-success" id="d-release" type="button">إفراج كامل للبائع</button>
                    <button class="btn btn-danger" id="d-refund" type="button">إعادة كاملة للمشتري</button>
                    <div class="d-flex gap-1 align-items-center">
                        <input type="number" min="0" step="0.01" class="form-control" id="d-partial-amount"
                               placeholder="مبلغ يُعاد للمشتري" dir="ltr" style="max-width:170px">
                        <button class="btn btn-warning" id="d-partial" type="button">تسوية جزئية</button>
                    </div>
                </div>
                <div class="small text-muted mt-2">التسوية الجزئية: المبلغ المدخل يعود للمشتري والباقي يذهب للبائع (مع خصم رسم المنصّة من حصّة البائع).</div>
                <div class="small mt-1 d-none" id="d-partial-preview"></div>
                <div class="text-danger small mt-1" id="d-error"></div>
            </div>
        </div>
    </div></div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/safe-payments') }}';
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let page = 1, lastPage = 1, status = 'disputed_open', currentUlid = null, currentPayment = null, currentCanDecide = false;

    const badge = (st) => ({
        'funded': '<span class="badge bg-primary">مجمّد</span>',
        'disputed': '<span class="badge bg-danger">متنازَع</span>',
        'released_to_seller': '<span class="badge bg-success">مُفرَج للبائع</span>',
        'refunded_to_buyer': '<span class="badge bg-secondary">مُعاد للمشتري</span>',
        'seller_rejected': '<span class="badge bg-secondary">رفض البائع</span>',
        'cancelled': '<span class="badge bg-secondary">أُلغي</span>',
        'expired': '<span class="badge bg-secondary">انتهت المهلة</span>',
        'pending_seller_acceptance': '<span class="badge bg-info">بانتظار البائع</span>',
        'in_delivery': '<span class="badge bg-primary">قيد التسليم</span>',
        'delivered': '<span class="badge bg-primary">بانتظار التأكيد</span>',
        'partially_refunded': '<span class="badge bg-warning text-dark">تسوية جزئية</span>',
    }[st] || `<span class="badge bg-light text-dark">${esc(st)}</span>`);

    const EVENT_LABEL = {
        created: 'إنشاء الطلب',
        seller_accepted: 'قبول البائع',
        seller_rejected: 'رفض البائع',
        in_delivery_marked: 'بدء التسليم',
        delivered_marked: 'تأكيد التسليم',
        delivery_code_verified: 'تأكيد التسليم برمز المشتري',
        delivery_code_failed: 'محاولة خاطئة لرمز التسليم',
        buyer_confirmed: 'تأكيد المشتري',
        released_to_seller: 'إفراج المبلغ للبائع',
        buyer_disputed: 'فتح نزاع',
        buyer_cancelled: 'إلغاء من المشتري',
        admin_resolved_release: 'الإدارة: إفراج للبائع',
        admin_resolved_refund: 'الإدارة: استرداد للمشتري',
        admin_resolved_partial: 'الإدارة: تسوية جزئية',
        expired: 'انتهت الصلاحية',
        evidence_added: 'رفع دليل',
        attachment_added: 'إضافة مرفق',
        note_added: 'إضافة ملاحظة',
    };

    const name = (u) => u ? `${esc((u.f_name ?? '') + ' ' + (u.l_name ?? ''))} <span class="text-muted small" dir="ltr">${esc(u.phone ?? '')}</span>` : '—';

    function renderTrust(t) {
        if (!t) return '';
        const badgeCls = t.badge === 'موثوق' ? 'bg-success'
            : (t.badge === 'نزاعات متكرّرة' ? 'bg-danger' : 'bg-secondary');
        return `<div class="small text-muted mt-1">
            <span class="badge ${badgeCls}">${esc(t.badge ?? '—')}</span>
            مكتملة: <b>${esc(t.completed_deals ?? 0)}</b> ·
            نزاعات: <b>${esc(t.disputed_deals ?? 0)}</b> (${esc(t.dispute_rate ?? 0)}%)
            ${t.member_since ? ` · عضو منذ <span dir="ltr">${esc(t.member_since)}</span>` : ''}
        </div>`;
    }

    function generateIdempotencyKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'sp-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12);
    }

    function buildFilterParams(includePage) {
        const params = new URLSearchParams();
        params.set('status', status);
        if (includePage) params.set('page', String(page));
        const q = document.getElementById('search-filter').value.trim();
        if (q) params.set('q', q);
        const from = document.getElementById('date-from').value;
        if (from) params.set('from', from);
        const to = document.getElementById('date-to').value;
        if (to) params.set('to', to);
        return params;
    }

    async function post(url, body) {
        const r = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'Idempotency-Key': generateIdempotencyKey(),
            },
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    async function load() {
        const tbody = document.getElementById('list-tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        const params = buildFilterParams(true);
        const r = await fetch(`${base}?${params.toString()}`, {headers: {'Accept': 'application/json'}});
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">تعذّر تحميل النزاعات. حاول مجدداً.</td></tr>';
            return;
        }
        const meta = j.meta || {};
        const items = meta.items || [];
        const pg = meta.pagination || {};
        lastPage = Math.max(1, Math.ceil((pg.total || 0) / (pg.per_page || 20)));
        document.getElementById('page-info').textContent = `${pg.total ?? 0} نتيجة — صفحة ${pg.current_page ?? 1}`;
        document.getElementById('open-count').textContent = meta.summary?.open_count ?? '0';
        document.getElementById('open-held').textContent = fmt(meta.summary?.open_held_amount);

        tbody.innerHTML = items.length ? items.map(p => `
            <tr>
                <td class="small font-monospace">${esc(p.payment_ulid)}</td>
                <td>${name(p.buyer)}</td>
                <td>${name(p.seller)}</td>
                <td class="fw-bold">${fmt(p.amount)} ر.ي</td>
                <td>${badge(p.status)}</td>
                <td class="small">${esc(p.disputed_at ?? '—')}</td>
                <td><button class="btn btn-sm btn-outline-primary" data-ulid="${esc(p.payment_ulid)}">فتح النزاع</button></td>
            </tr>`).join('')
            : '<tr><td colspan="7" class="text-center text-muted py-4">لا نزاعات — كل شيء صافٍ 🎉</td></tr>';
    }

    const STAGE_ORDER = ['created', 'in_delivery', 'delivered', 'dispute', 'admin_review'];
    const STAGE_LABEL = {
        created: 'عند الإنشاء', in_delivery: 'عند الشحن', delivered: 'عند التسليم',
        dispute: 'مع النزاع', admin_review: 'مراجعة الإدارة',
    };
    const ROLE_LABEL = {buyer: 'المشتري', seller: 'البائع', admin: 'الإدارة'};

    /// كل دليل يُعرض بصاحبه ووقته وبصمته — وبتحذير صريح إن لم تعد البصمة
    /// تطابق المسجَّلة، لأن قراراً مالياً يُبنى على ملفّ مشكوك فيه أسوأ من
    /// قرار يُبنى على لا شيء.
    function renderEvidence(groups) {
        const el = document.getElementById('d-evidence');
        const stages = STAGE_ORDER.filter(s => (groups[s] || []).length)
            .concat(Object.keys(groups).filter(s => !STAGE_ORDER.includes(s) && (groups[s] || []).length));

        if (!stages.length) {
            el.innerHTML = '<div class="alert alert-warning py-2 small mb-0">'
                + 'لا أدلّة مرفوعة في هذه العملية. القرار هنا يستند إلى الرواية وسجلّ الأحداث وحدهما.'
                + '</div>';
            return;
        }

        el.innerHTML = stages.map(stage => `
            <div class="mb-2">
                <div class="small fw-bold text-muted mb-1">${esc(STAGE_LABEL[stage] || stage)}
                    <span class="text-muted fw-normal">(${(groups[stage] || []).length})</span></div>
                <div class="d-flex flex-wrap gap-2">
                    ${(groups[stage] || []).map(ev => `
                        <div class="border rounded p-1 text-center" style="width:132px">
                            <a href="${esc(ev.url)}" target="_blank" rel="noopener">
                                ${ev.mime === 'application/pdf'
                                    ? '<div class="d-flex align-items-center justify-content-center bg-light" style="height:96px"><span class="text-danger">PDF</span></div>'
                                    : `<img src="${esc(ev.url)}" alt="دليل" style="width:100%;height:96px;object-fit:cover">`}
                            </a>
                            <div class="small mt-1">${esc(ROLE_LABEL[ev.role] || ev.role)}</div>
                            <div class="text-muted" style="font-size:10px">${esc(ev.uploaded_at ?? '')}</div>
                            <div class="text-muted font-monospace" style="font-size:10px" dir="ltr">${esc(ev.fingerprint ?? '')}</div>
                            ${ev.integrity_ok === false
                                ? '<div class="badge bg-danger mt-1" style="font-size:9px">بصمة غير مطابقة</div>'
                                : ''}
                            ${ev.note ? `<div class="small text-muted mt-1" style="font-size:10px">${esc(ev.note)}</div>` : ''}
                        </div>`).join('')}
                </div>
            </div>`).join('');
    }

    const AUDIT_LABEL = {
        SAFE_PAYMENT_CREATED: 'إنشاء الطلب',
        SAFE_PAYMENT_DISPUTED: 'فتح المشتري نزاعاً',
        SAFE_PAYMENT_DISPUTE_VIEWED: 'فتح موظّف ملفّ النزاع',
        SAFE_PAYMENT_EVIDENCE_VIEWED: 'اطّلاع موظّف على دليل',
        SAFE_PAYMENT_ADMIN_RELEASE: 'قرار: إفراج كامل للبائع',
        SAFE_PAYMENT_ADMIN_REFUND: 'قرار: إعادة كاملة للمشتري',
        SAFE_PAYMENT_ADMIN_PARTIAL: 'قرار: تسوية جزئية',
    };
    const SEV_CLASS = {critical: 'text-danger fw-bold', warning: 'text-warning-emphasis', notice: 'text-muted'};

    function renderAudit(rows, paymentId) {
        const link = document.getElementById('d-audit-link');
        link.href = `{{ url('admin/amial/audit') }}?subject_type=safe_payment&subject_id=${encodeURIComponent(paymentId ?? '')}`;

        document.getElementById('d-audit').innerHTML = rows.length ? rows.map(r => `
            <li class="list-group-item small d-flex justify-content-between align-items-start">
                <span class="${SEV_CLASS[r.severity] || ''}">
                    ${esc(AUDIT_LABEL[r.action] || r.action)}
                    ${r.actor_name ? `— <span class="text-muted">${esc(r.actor_name)}</span>` : ''}
                    ${r.reason ? `<div class="text-muted" style="font-size:11px">${esc(r.reason)}</div>` : ''}
                </span>
                <span class="text-muted text-nowrap ms-2" dir="ltr">${esc(r.at ?? '')}</span>
            </li>`).join('')
            : '<li class="list-group-item text-muted small">لا سجلّات بعد</li>';
    }

    function updatePartialPreview() {
        const previewEl = document.getElementById('d-partial-preview');
        const raw = document.getElementById('d-partial-amount').value.trim();
        if (!raw || !currentPayment) {
            previewEl.classList.add('d-none');
            previewEl.innerHTML = '';
            return;
        }
        const held = Number(currentPayment.held_amount ?? currentPayment.amount ?? 0);
        const buyerAmt = Number(raw);
        previewEl.classList.remove('d-none');
        if (!Number.isFinite(buyerAmt) || buyerAmt < 0 || buyerAmt > held) {
            previewEl.className = 'small mt-1 text-danger';
            previewEl.textContent = `المبلغ يجب أن يكون بين 0 و ${fmt(held)} ر.ي.`;
            return;
        }
        const sellerGross = Math.max(0, held - buyerAmt);
        previewEl.className = 'small mt-1 text-primary';
        previewEl.innerHTML = `معاينة الأثر المالي: يستردّ المشتري <b>${fmt(buyerAmt)} ر.ي</b> · يُفرَج للبائع <b>${fmt(sellerGross)} ر.ي</b> (قبل رسم المنصّة).`;
    }

    function renderFourEyesWarning(meta) {
        const el = document.getElementById('d-four-eyes');
        const actors = Array.isArray(meta.previous_actors) ? meta.previous_actors : [];
        const parts = [];
        if (meta.four_eyes_blocked) {
            parts.push('<div class="alert alert-danger py-2 small mb-2"><b>تنبيه قاعدة أربع عيون:</b> سبق لك تنفيذ إجراء تعديلي على هذه العملية — يلزم موظّف آخر لحسم النزاع.</div>');
        }
        if (actors.length) {
            const list = actors.map(a => `${esc(a.name || '—')} (${esc(a.actions ?? 0)})`).join('، ');
            parts.push(`<div class="alert alert-warning py-2 small mb-0"><b>تحذير أربع عيون (تفاعلات سابقة):</b> ${list}</div>`);
        }
        if (parts.length) {
            el.innerHTML = parts.join('');
            el.classList.remove('d-none');
        } else {
            el.innerHTML = '';
            el.classList.add('d-none');
        }
    }

    async function openDispute(ulid) {
        currentUlid = ulid;
        currentPayment = null;
        currentCanDecide = false;
        document.getElementById('d-error').textContent = '';
        document.getElementById('d-reason').value = '';
        document.getElementById('d-partial-amount').value = '';
        document.getElementById('d-partial-preview').classList.add('d-none');
        document.getElementById('d-four-eyes').classList.add('d-none');
        document.getElementById('d-info').innerHTML = 'جارٍ التحميل…';
        document.getElementById('d-events').innerHTML = '';
        new bootstrap.Modal('#modal-dispute').show();

        document.getElementById('d-evidence').innerHTML = '';

        const r = await fetch(`${base}/${ulid}`, {headers: {'Accept': 'application/json'}});
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) {
            document.getElementById('d-info').innerHTML = '<div class="alert alert-danger mb-0">تعذّر فتح ملف النزاع.</div>';
            return;
        }
        const meta = j.meta || {};
        const p = meta.payment || {};
        currentPayment = p;
        currentCanDecide = meta.can_decide !== false;
        const disputeReason = meta.dispute_reason || p.dispute_reason || null;
        const trust = meta.trust_summary || {};

        document.getElementById('d-title').textContent = `نزاع ${p.payment_ulid ?? ''}${p.title ? ' — ' + p.title : ''}`;
        renderFourEyesWarning(meta);
        document.getElementById('d-info').innerHTML = `
            <div class="row g-2">
                ${p.title ? `<div class="col-12"><div class="border rounded p-2 bg-light"><b>عنوان الصفقة:</b> ${esc(p.title)}</div></div>` : ''}
                <div class="col-md-6"><div class="border rounded p-2">المشتري: ${name(p.buyer)}${renderTrust(trust.buyer)}</div></div>
                <div class="col-md-6"><div class="border rounded p-2">البائع: ${name(p.seller)}${renderTrust(trust.seller)}</div></div>
                <div class="col-md-4"><div class="border rounded p-2">المبلغ: <b>${fmt(p.amount)} ر.ي</b></div></div>
                <div class="col-md-4"><div class="border rounded p-2">الحالة: ${badge(p.status)}</div></div>
                <div class="col-md-4"><div class="border rounded p-2 small">الوصف: ${esc(p.description ?? '—')}</div></div>
                ${p.delivery_terms ? `<div class="col-12"><div class="border rounded p-2 small"><b>شروط التسليم:</b> ${esc(p.delivery_terms)}</div></div>` : ''}
                ${meta.dispute_reason_label ? `<div class="col-12"><div class="border rounded p-2 bg-warning-subtle"><b>تصنيف الشكوى:</b> ${esc(meta.dispute_reason_label)}</div></div>` : ''}
                ${disputeReason ? `<div class="col-12"><div class="border rounded p-2 bg-danger-subtle"><b>سبب النزاع:</b> ${esc(disputeReason)}</div></div>` : ''}
                <div class="col-12"><div class="border rounded p-2 small">
                    ${meta.delivery_code_verified
                        ? '<span class="badge bg-success">التسليم مؤكَّد برمز المشتري</span> — إنكار الاستلام بعد ذلك يحتاج دليلاً قوياً.'
                        : `<span class="badge bg-secondary">لم يُؤكَّد برمز</span> — التسليم ادّعاء من طرف واحد (محاولات الرمز: ${esc(meta.delivery_code_attempts ?? 0)}).`}
                </div></div>
            </div>`;

        renderEvidence(meta.evidence || {});
        renderAudit(meta.audit_trail || [], p.id);
        const canResolve = p.status === 'disputed' && !p.admin_resolved_at && currentCanDecide;
        document.getElementById('d-resolution-card').classList.toggle('d-none', !canResolve);
        document.getElementById('d-partial-amount').max = p.held_amount ?? p.amount ?? '';
        document.getElementById('d-events').innerHTML = (p.events || []).map(ev => {
            const evType = ev.event_type ?? ev.type ?? '';
            const evLabel = EVENT_LABEL[evType] || evType;
            const evNote = ev.note ?? ev.description ?? '';
            return `<li class="list-group-item small d-flex justify-content-between">
                <span><b>${esc(evLabel)}</b>${evNote ? ` — ${esc(evNote)}` : ''}</span>
                <span class="text-muted text-nowrap ms-2" dir="ltr">${esc(ev.created_at ?? '')}</span>
            </li>`;
        }).join('') || '<li class="list-group-item text-muted small">لا أحداث</li>';
    }

    document.getElementById('list-tbody').addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-ulid]');
        if (btn) openDispute(btn.dataset.ulid);
    });

    async function resolve(action, extra) {
        const errEl = document.getElementById('d-error'); errEl.textContent = '';
        if (!currentCanDecide) {
            errEl.textContent = 'لا تملك صلاحية حسم النزاعات.'; return;
        }
        if (!currentPayment || currentPayment.status !== 'disputed' || currentPayment.admin_resolved_at) {
            errEl.textContent = 'هذه العملية ليست نزاعاً مفتوحاً للحسم.'; return;
        }
        const reason = document.getElementById('d-reason').value.trim();
        if (reason.length < 10) { errEl.textContent = 'سبب القرار مطلوب (10 أحرف فأكثر)'; return; }
        if (action === 'partial') {
            const rawAmt = String(extra?.buyer_refund_amount ?? '').trim();
            const held = Number(currentPayment.held_amount ?? currentPayment.amount ?? 0);
            const numAmt = Number(rawAmt);
            if (!rawAmt || !Number.isFinite(numAmt) || numAmt < 0 || numAmt > held) {
                errEl.textContent = `أدخل مبلغاً صحيحاً للمشتري بين 0 و ${fmt(held)} ر.ي.`;
                return;
            }
        }
        const labels = {release: 'إفراج كامل للبائع', refund: 'إعادة كاملة للمشتري', partial: 'تسوية جزئية'};
        if (!window.confirm(`هل تؤكد ${labels[action]}؟ القرار المالي نهائي ويُسجّل باسمك.`)) return;
        const buttons = ['d-release', 'd-refund', 'd-partial'].map(id => document.getElementById(id));
        buttons.forEach(b => b.disabled = true);
        try {
            const j = await post(`${base}/${currentUlid}/${action}`, Object.assign({reason}, extra || {}));
            bootstrap.Modal.getInstance(document.getElementById('modal-dispute')).hide();
            alert(j.message || 'تم الحسم'); load();
        } catch (err) { errEl.textContent = err.message; }
        finally { buttons.forEach(b => b.disabled = false); }
    }

    document.getElementById('d-release').addEventListener('click', () => resolve('release'));
    document.getElementById('d-refund').addEventListener('click', () => resolve('refund'));
    document.getElementById('d-partial').addEventListener('click', () =>
        resolve('partial', {buyer_refund_amount: document.getElementById('d-partial-amount').value}));
    document.getElementById('d-partial-amount').addEventListener('input', updatePartialPreview);

    document.getElementById('status-filter').addEventListener('change', (e) => { status = e.target.value; page = 1; load(); });
    document.getElementById('date-from').addEventListener('change', () => { page = 1; load(); });
    document.getElementById('date-to').addEventListener('change', () => { page = 1; load(); });
    document.getElementById('search-button').addEventListener('click', () => { page = 1; load(); });
    document.getElementById('search-filter').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); page = 1; load(); }
    });
    document.getElementById('export-csv').addEventListener('click', () => {
        const params = buildFilterParams(false);
        params.set('export', 'csv');
        window.location.href = `${base}?${params.toString()}`;
    });
    document.getElementById('prev-page').addEventListener('click', () => { if (page > 1) { page--; load(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; load(); } });

    load();
})();
</script>
@endpush
