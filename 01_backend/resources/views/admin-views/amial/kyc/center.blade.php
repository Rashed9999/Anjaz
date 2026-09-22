@extends('layouts.admin.app')
@section('title', 'مركز التحقق والهوية')
@section('content')
<style>
    .vc-card{background:var(--amial-surface,#fff);border:1px solid #dfe7ee;border-radius:15px}
    .vc-card header{padding:14px 17px;border-bottom:1px solid #e9eff5}
    .vc-stat{border:1px solid #dfe7ee;border-radius:13px;padding:13px;background:#fff;min-width:125px}
    .vc-case{display:block;width:100%;text-align:right;border:0;border-bottom:1px solid #edf1f4;
        padding:13px 15px;background:#fff;cursor:pointer}
    .vc-case:hover,.vc-case.active{background:#f0f7ff}
    .vc-case.active{border-right:4px solid var(--amial-primary,#2563eb)}
    .vc-sub{font-size:12px;color:#61748a}
    .vc-doc{border:1px solid #dae5ee;border-radius:12px;padding:13px;background:#fff}
    .vc-badge{display:inline-block;font-size:12px;padding:3px 8px;border-radius:25px;background:#edf2f7;color:#40536a}
    .vc-filter.active{color:#fff!important;background:var(--amial-primary,#2563eb)!important}
    .vc-section{border:1px solid #e2eaf0;border-radius:14px;padding:17px;margin-bottom:15px;background:#fff}
    .vc-section h5{font-size:16px;font-weight:700;margin-bottom:12px}
    .vc-preview{width:100%;height:330px;border:1px solid #dbe4ed;border-radius:11px;object-fit:contain;background:#f8fafc}
    .vc-ocr-field{min-width:175px}
    @media(min-width:992px){.vc-queue{max-height:78vh;overflow:auto}.vc-profile{max-height:78vh;overflow:auto}}
</style>
<div class="content container-fluid" id="verification-center" data-testid="unified-verification-center" dir="rtl">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="page-header-title mb-1">🛡️ مركز التحقق والهوية</h2>
            <p class="text-muted mb-0">طلبات العملاء الجدد والترقيات والسكن والهوية وقرار الاعتماد، من ملف واحد.</p>
        </div>
        <button class="btn btn-outline-primary" id="vc-refresh" type="button">↻ تحديث الحالات</button>
    </div>

    <div id="vc-notice" class="mb-3" role="status" aria-live="polite"></div>
    <div class="row g-2 mb-3" id="vc-stats" aria-label="مؤشرات الحالات"></div>

    <div class="vc-card p-3 mb-3">
        <div class="row g-2 align-items-center">
            <div class="col-md-5">
                <label class="form-label small" for="vc-search">البحث باسم العميل أو رقمه أو معرّف الحساب</label>
                <div class="input-group">
                    <input id="vc-search" class="form-control" type="search" autocomplete="off" placeholder="اسم / هاتف / رقم حساب">
                    <button id="vc-search-btn" class="btn btn-primary" type="button">بحث</button>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="vc-role">نوع الحساب</label>
                <select id="vc-role" class="form-select">
                    <option value="all">جميع الأنواع</option>
                    <option value="عميل">عملاء</option>
                    <option value="تاجر">تجّار</option>
                    <option value="وكيل">وكلاء</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small d-block">مرحلة الطلب</label>
                <div class="d-flex flex-wrap gap-1" id="vc-filters">
                    <button class="btn btn-outline-primary btn-sm vc-filter active" data-stage="all" type="button">الكل</button>
                    <button class="btn btn-outline-primary btn-sm vc-filter" data-stage="new" type="button">جديد</button>
                    <button class="btn btn-outline-primary btn-sm vc-filter" data-stage="residence" type="button">السكن</button>
                    <button class="btn btn-outline-primary btn-sm vc-filter" data-stage="documents" type="button">المستندات</button>
                    <button class="btn btn-outline-primary btn-sm vc-filter" data-stage="decision" type="button">قرار نهائي</button>
                </div>
            </div>
        </div>
        <div class="small text-muted mt-2">المؤشرات تشمل الطلبات المعروضة في الطابور الحالي؛ البحث يصل إلى الحسابات الأخرى.</div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="vc-card vc-queue">
                <header class="d-flex justify-content-between align-items-center">
                    <strong>صندوق الطلبات</strong><span class="vc-badge" id="vc-list-count">—</span>
                </header>
                <div id="vc-list" data-testid="unified-verification-queue">
                    <div class="p-4 text-muted">تحميل طلبات المراجعة...</div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="vc-card vc-profile">
                <header class="d-flex justify-content-between align-items-center">
                    <strong>ملف التحقق والقرار</strong><span id="vc-selected" class="vc-sub">اختر حسابًا</span>
                </header>
                <div id="vc-detail" class="p-3" data-testid="unified-verification-dossier">
                    <div class="text-center text-muted p-5">اختر أحد الطلبات لعرض المستندات والسكن والاسم وقرار الحساب هنا.</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    'use strict';
    const ROUTES = {
        queue: @json(route('admin.amial.kyc.center.queue')),
        account: @json(url('admin/amial/kyc/center/accounts')),
        document: @json(url('admin/amial/kyc/documents')),
        residence: @json(url('admin/amial/kyc/residence')),
        accountDecision: @json(url('admin/amial/hub/users')),
        print: @json(url('admin/amial/hub/account')),
        csrf: @json(csrf_token()),
    };
    const GOVERNORATES = @json($governorates);
    const STAGES = {new:'تسجيل جديد',residence:'مراجعة السكن',documents:'مراجعة المستندات',decision:'جاهز لفحص القرار'};
    const DOC_STATES = {pending:'بانتظار المراجعة',approved:'معتمد',rejected:'مرفوض',superseded:'مستبدل'};
    const el = id => document.getElementById(id);
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let items = [], chosen = null, stage = 'all', role = 'all', current = null, selectedDoc = null, loadVersion = 0;

    async function req(url, body) {
        const options = {headers: {'Accept':'application/json'}, credentials:'same-origin'};
        if (body !== undefined) {
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-TOKEN'] = ROUTES.csrf;
            options.body = JSON.stringify(body);
        }
        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false) {
            const errors = data.errors && typeof data.errors === 'object'
                ? Object.values(data.errors).flat().join(' · ') : '';
            throw new Error(data.message || errors || ('تعذر إتمام الطلب (HTTP ' + response.status + ')'));
        }
        return data;
    }

    function notice(message, kind) {
        el('vc-notice').innerHTML = message
            ? '<div class="alert alert-' + (kind || 'info') + ' mb-0">' + esc(message) + '</div>' : '';
    }
    function statusBadge(state) {
        const color = state === 'verified' || state === 'approved' ? 'success'
            : state === 'rejected' ? 'danger'
            : state === 'pending' ? 'warning text-dark' : 'secondary';
        const labels = {verified:'معتمد',approved:'معتمد',pending:'بانتظار المراجعة',
            not_submitted:'لم يُقدم',needs_more_evidence:'يحتاج دليلاً إضافيًا',
            rejected:'مرفوض',expired:'منتهي'};
        return '<span class="badge bg-' + color + '">' + esc(labels[state] || state || 'غير متاح') + '</span>';
    }
    function stats(meta) {
        const names = [['all','كل الطلبات'],['new','تسجيل جديد'],['residence','السكن'],
            ['documents','الهوية والمستندات'],['decision','قرار نهائي']];
        el('vc-stats').innerHTML = names.map(item =>
            '<div class="col-6 col-lg"><div class="vc-stat"><div class="vc-sub">' + item[1] +
            '</div><strong style="font-size:23px">' + esc((meta.counts || {})[item[0]] || 0) +
            '</strong></div></div>').join('');
    }

    function filtered() {
        return items.filter(item => (stage === 'all' || stage === item.stage)
            && (role === 'all' || role === item.role));
    }
    function renderList() {
        const rows = filtered();
        el('vc-list-count').textContent = rows.length + ' حالة';
        el('vc-list').innerHTML = rows.length ? rows.map(item => {
            const isActive = Number(chosen) === Number(item.id);
            return '<button type="button" class="vc-case ' + (isActive ? 'active' : '') +
                '" data-case="' + item.id + '" aria-pressed="' + (isActive ? 'true' : 'false') + '">' +
                '<div class="d-flex justify-content-between gap-2"><strong>' + esc(item.name) +
                '</strong><span class="vc-badge">' + esc(item.role) + '</span></div>' +
                '<div class="vc-sub" dir="ltr">#' + item.id + ' · ' + esc(item.phone) + '</div>' +
                '<div class="d-flex justify-content-between mt-2 align-items-center">' +
                '<span class="badge bg-' + (item.stage === 'decision' ? 'success' :
                    item.stage === 'documents' ? 'warning text-dark' : 'primary') + '">' +
                esc(STAGES[item.stage]) + '</span>' +
                '<small class="vc-sub">' + (item.restricted ? '🔒 مراجعة مقيدة' :
                    esc(item.registered_at || '')) + '</small></div></button>';
        }).join('') : '<div class="p-4 text-center text-muted">لا توجد حالات مطابقة لهذا التصنيف.</div>';
    }
    async function loadQueue(keepCase) {
        el('vc-list').innerHTML = '<div class="p-3 text-muted">تحميل الطابور...</div>';
        try {
            const query = el('vc-search').value.trim();
            const response = await req(ROUTES.queue + (query ? '?q=' + encodeURIComponent(query) : ''));
            items = response.data || [];
            stats(response.meta || {});
            if (!keepCase && !items.some(x => Number(x.id) === Number(chosen))) {
                chosen = items.length ? items[0].id : null;
            }
            renderList();
            if (chosen) await openCase(chosen);
            else el('vc-detail').innerHTML =
                '<div class="text-center p-5 text-muted">لا توجد قضية محددة. استخدم البحث أو اختر نوع طلب.</div>';
        } catch (error) {
            el('vc-list').innerHTML = '<div class="p-3 text-danger">' + esc(error.message) + '</div>';
            notice(error.message, 'danger');
        }
    }

    function blockers(lines, title, kind) {
        if (!lines || !lines.length) return '';
        return '<div class="alert alert-' + (kind || 'warning') + ' py-2"><strong>' + esc(title) +
            '</strong><ul class="mb-0 mt-1">' + lines.map(x => '<li>' + esc(x) + '</li>').join('') +
            '</ul></div>';
    }
    function docCard(d, permissions) {
        const canDecide = permissions.review_documents && d.status === 'pending';
        const biometric = d.type === 'selfie' && !permissions.view_biometric;
        const open = permissions.view_documents && !biometric;
        return '<div class="vc-doc mb-2">' +
            '<div class="d-flex justify-content-between gap-2 flex-wrap"><strong>' + esc(d.type_label) +
            '</strong><span>' + statusBadge(d.status) + '</span></div>' +
            '<div class="vc-sub mb-2">رفع: ' + esc(d.uploaded_at || '—') +
            (d.expires_at ? ' · ينتهي: ' + esc(d.expires_at) : '') + '</div>' +
            (d.rejection_reason ? '<div class="text-danger small mb-2">' + esc(d.rejection_reason) + '</div>' : '') +
            (biometric ? '<div class="alert alert-warning py-1 small">صورة الوجه محمية بصلاحية مستقلة.</div>' : '') +
            '<div class="d-flex gap-1 flex-wrap">' +
            (open ? '<button class="btn btn-outline-primary btn-sm" data-action="doc-view" data-id="' +
                d.id + '" type="button">عرض آمن للمستند</button>' : '') +
            (canDecide ? '<button class="btn btn-success btn-sm" data-action="doc-approve" data-id="' +
                d.id + '" type="button">اعتماد المستند</button>' +
                '<button class="btn btn-outline-danger btn-sm" data-action="doc-reject" data-id="' +
                d.id + '" type="button">رفض المستند</button>' : '') +
            '</div></div>';
    }

    function residenceCard(residence, review, permissions) {
        const pending = review && residence.status === 'pending';
        return '<section class="vc-section" id="vc-residence"><h5>🏠 إثبات السكن والنطاق التشغيلي ' +
            statusBadge(residence.status) + '</h5>' +
            '<div class="row g-2 small mb-3">' +
            '<div class="col-md-6">محافظة الميلاد: <strong>' + esc(residence.birth_governorate_name || '—') + '</strong></div>' +
            '<div class="col-md-6">السكن الحالي: <strong>' + esc(residence.declared_governorate_name || '—') + '</strong></div>' +
            '<div class="col-md-6">المديرية: ' + esc(residence.residence_district || '—') + '</div>' +
            '<div class="col-md-6">الحي: ' + esc(residence.residence_area || '—') + '</div>' +
            '<div class="col-md-6">الاسم في الإثبات: ' + esc(residence.document_name || 'لم يؤكده المراجع') + '</div>' +
            '<div class="col-md-6">نطاق الخدمة: ' + (residence.operational ? 'داخل النطاق الحالي' : 'خارج النطاق/غير محسوم') + '</div>' +
            '</div>' +
            (pending ? '<div class="alert alert-light border small">نوع الدليل: ' +
                esc(review.evidence_type) + ' · قوته: ' + esc(review.evidence_strength) +
                (review.evidence_strength === 'supporting' ? ' — لا يكفي للاعتماد منفردًا' : '') + '</div>' +
                (review.document_id && permissions.view_documents ?
                    '<button class="btn btn-outline-primary btn-sm mb-2" type="button" data-action="doc-view" data-id="' +
                    review.document_id + '">عرض مستند السكن المحمي</button>' : '') +
                (permissions.review_residence ?
                    '<div class="row g-2"><div class="col-md-6"><label class="form-label small" for="vc-res-name">الاسم كما يظهر في المستند</label>' +
                    '<input id="vc-res-name" class="form-control" maxlength="300" value="' +
                    esc(review.document_name || '') + '"></div>' +
                    '<div class="col-md-6"><label class="form-label small" for="vc-res-note">ملاحظة المطابقة عند الاختلاف الجزئي</label>' +
                    '<input id="vc-res-note" class="form-control" maxlength="500" value="' +
                    esc(review.name_review_note || '') + '"></div></div>' +
                    '<div class="d-flex flex-wrap gap-2 mt-3">' +
                    '<button class="btn btn-success btn-sm" data-action="res-approve" data-id="' + review.id +
                    '" type="button"' + (review.evidence_strength === 'supporting' ? ' disabled' : '') + '>اعتماد السكن</button>' +
                    '<button class="btn btn-outline-warning btn-sm" data-action="res-more" data-id="' +
                    review.id + '" type="button">طلب دليل إضافي</button>' +
                    '<button class="btn btn-outline-danger btn-sm" data-action="res-reject" data-id="' +
                    review.id + '" type="button">رفض السكن</button></div>' : '<div class="vc-sub">عرض فقط — صلاحية القرار غير متاحة.</div>') : '') +
            (residence.decision_reason ? '<p class="text-danger small mt-2">سبب القرار: ' +
                esc(residence.decision_reason) + '</p>' : '') + '</section>';
    }

    function decisionCard(data) {
        const a = data.account, ev = data.evidence || {}, permissions = data.permissions || {};
        const blocked = ev.blockers || [];
        const options = GOVERNORATES.map(g => '<option value="' + esc(g.code) + '"' +
            (g.code === a.governorate ? ' selected' : '') + '>' + esc(g.name) + '</option>').join('');
        return '<section class="vc-section" id="vc-decision"><h5>✅ القرار النهائي وترقية الحساب</h5>' +
            blockers(blocked, 'ما يمنع الاعتماد الآن', 'warning') +
            '<div class="small mb-2">المستوى المطلوب: <strong>' + a.target_tier +
            '</strong> · إثبات ملكية الهوية: <strong>' +
            (ev.ownership && ev.ownership.ready ? 'مكتمل' : 'غير مكتمل') + '</strong></div>' +
            (permissions.decide_account ?
                '<label class="form-label small" for="vc-governorate">محافظة السكن كما راجعتها في الملف</label>' +
                '<select id="vc-governorate" class="form-select mb-2">' +
                '<option value="">اختر المحافظة</option>' + options + '</select>' +
                '<div class="d-flex gap-2 flex-wrap">' +
                '<button type="button" class="btn btn-success" data-action="account-approve"' +
                (permissions.activate_ready ? '' : ' disabled') + '>اعتماد وترقية إلى المستوى ' +
                a.target_tier + '</button>' +
                (!a.verified && ev.documents && ev.documents.length ?
                    '<button type="button" class="btn btn-outline-danger" data-action="account-reject">رفض طلب التوثيق</button>' : '') +
                '</div>' : '<div class="vc-sub">هذا الحساب للعرض فقط؛ قرار التفعيل يتطلب صلاحية اعتماد مستقلة.</div>') +
            (permissions.decide_account && !permissions.activate_ready ?
                '<div class="vc-sub mt-2">يتفعّل زر الاعتماد تلقائيًا بعد استكمال الشروط الظاهرة أعلاه وتحديث الملف.</div>' : '') +
            '</section>';
    }

    function renderCase(data) {
        current = data;
        const a = data.account, ev = data.evidence || {}, docs = ev.documents || [], p = data.permissions || {};
        el('vc-selected').textContent = '#' + a.id + ' — ' + a.role;
        el('vc-detail').innerHTML =
            '<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">' +
            '<div><h3 class="mb-1">' + esc(a.name) + '</h3><div class="vc-sub" dir="ltr">' +
            esc(a.phone) + ' · #' + a.id + '</div>' +
            '<div class="mt-1"><span class="vc-badge">' + esc(a.role) + '</span> ' +
            '<span class="vc-badge">المستوى الفعلي ' + a.effective_tier + '</span> ' +
            (a.restricted ? '<span class="badge bg-danger">مراجعة مقيدة</span>' : '') + '</div></div>' +
            (p.view_documents ? '<a class="btn btn-outline-secondary btn-sm align-self-start" target="_blank" rel="noopener" href="' +
                ROUTES.print + '/' + a.id + '/print">🖨 طباعة ملف الحساب</a>' : '') + '</div>' +
            '<section class="vc-section"><h5>👤 البيانات والتحقق الأساسي</h5>' +
            '<div class="row g-2 small"><div class="col-md-6">الهاتف: ' +
            (a.phone_verified ? '<span class="text-success">موثق ✓</span>' : '<span class="text-warning">غير موثق</span>') +
            '</div><div class="col-md-6">حالة الحساب: ' + (a.verified ? 'هوية معتمدة' : 'بانتظار التوثيق') +
            '</div><div class="col-md-6">اسم التسجيل الرباعي: <strong>' +
            esc((ev.legal_name || {}).declared || '—') + '</strong></div>' +
            '<div class="col-md-6">الاسم المؤكد من الهوية: <strong>' +
            esc((ev.legal_name || {}).identity_document_name || 'لم يؤكد') + '</strong></div>' +
            '</div>' + blockers((ev.reuse || {}).warnings, 'تنبيهات تكرار', 'info') + '</section>' +
            residenceCard(data.residence || {}, data.residence_review, p) +
            '<section class="vc-section" id="vc-documents"><h5>🪪 مستندات الهوية والملكية</h5>' +
            '<div class="small text-muted mb-2">مراجعة المستند منفصلة عن اعتماد الحساب. الصورة الأصلية لا تعرض دون علامة مائية.</div>' +
            (docs.length ? docs.map(d => docCard(d, p)).join('') :
                '<div class="alert alert-secondary">لم يرفع العميل مستندات في سجل التوثيق الحديث.</div>') +
            '<div id="vc-document-view" class="mt-3"></div>' +
            blockers((ev.ownership || {}).blockers, 'إثبات ملكية الهوية', 'warning') +
            '</section>' + decisionCard(data);
    }

    async function openCase(id) {
        chosen = Number(id);
        renderList();
        const version = ++loadVersion;
        el('vc-detail').innerHTML = '<div class="p-5 text-muted text-center">فتح ملف الحساب ومراجعة الأدلة...</div>';
        try {
            const response = await req(ROUTES.account + '/' + encodeURIComponent(id));
            if (version !== loadVersion) return;
            renderCase(response.data);
        } catch (error) {
            if (version !== loadVersion) return;
            el('vc-detail').innerHTML = '<div class="alert alert-danger">' + esc(error.message) + '</div>';
        }
    }

    async function viewDocument(id) {
        if (!current || !current.permissions.view_documents) return;
        const doc = (current.evidence.documents || []).find(d => Number(d.id) === Number(id));
        const isResidence = current.residence_review &&
            Number(current.residence_review.document_id) === Number(id);
        if (!doc && !isResidence) return;
        if (doc && doc.type === 'selfie' && !current.permissions.view_biometric) return;
        selectedDoc = Number(id);
        const holder = el('vc-document-view');
        const url = ROUTES.document + '/' + encodeURIComponent(id) +
            '/file?reason=' + encodeURIComponent('مراجعة الملف من مركز التحقق الموحد');
        const mime = doc ? doc.mime : '';
        const preview = mime && mime.indexOf('image/') === 0
            ? '<img class="vc-preview" alt="نسخة معاينة مائية" src="' + url + '">'
            : '<iframe class="vc-preview" title="معاينة مستند محمي" src="' + url + '"></iframe>';
        holder.innerHTML = '<div class="card"><div class="card-header">' +
            '<strong>المعاينة الآمنة للمستند #' + id + '</strong></div><div class="card-body">' +
            preview + '<div class="small text-muted my-2">نسخة مشاهدة بعلامة مائية ومسار تدقيق.</div>' +
            '<div id="vc-ocr"></div></div></div>';
        if (!doc || !['national_id_front','national_id_back','passport'].includes(doc.type)) return;
        try {
            const response = await req(ROUTES.document + '/' + id + '/ocr');
            if (selectedDoc !== Number(id)) return;
            const o = response.data || {};
            if (o.applicable === false) {
                el('vc-ocr').innerHTML = '<div class="alert alert-info">' +
                    esc(o.not_applicable_reason || 'الاستخراج غير متاح لهذه الوثيقة') + '</div>';
                return;
            }
            const labels = {full_name:'الاسم في الهوية',national_id:'رقم الهوية',
                date_of_birth:'تاريخ الميلاد',expiry_date:'تاريخ الانتهاء',
                gender:'الجنس',country:'الدولة'};
            const fields = Object.keys(labels).map(key => {
                const f = (o.fields || {})[key];
                const val = (o.verified || {})[key] || (f ? f.value : '');
                return '<div class="col-md-6"><label class="form-label small">' + labels[key] +
                    '</label><input class="form-control vc-ocr-field" data-ocr-field="' + key +
                    '" value="' + esc(val || '') + '"' + (current.permissions.review_documents ? '' : ' readonly') + '></div>';
            }).join('');
            el('vc-ocr').innerHTML = '<h6 class="mt-3">الحقول المستخرجة — مراجعة بشرية</h6>' +
                blockers((o.findings || []).map(f => f.message), 'تنبيهات الاستخراج', 'warning') +
                '<div class="row g-2">' + fields + '</div>' +
                (current.permissions.review_documents ?
                    '<div class="d-flex gap-2 mt-3">' +
                    '<button class="btn btn-primary btn-sm" type="button" data-action="ocr-confirm" data-id="' +
                    id + '">إقرار الحقول وربط الهوية</button>' +
                    '<button class="btn btn-outline-secondary btn-sm" type="button" data-action="ocr-reread" data-id="' +
                    id + '">إعادة القراءة</button></div>' : '');
        } catch (error) {
            el('vc-ocr').innerHTML = '<div class="alert alert-warning mt-2">' + esc(error.message) + '</div>';
        }
    }

    async function act(button) {
        if (!current) return;
        const action = button.dataset.action;
        const id = Number(button.dataset.id || 0);
        if (button.disabled) return;
        let url = null, body = null;
        if (action === 'doc-view') return viewDocument(id);
        if (action === 'doc-approve') {
            if (!current.permissions.review_documents || !window.confirm('هل راجعت المستند وتريد اعتماده؟ هذا لا يعتمد الحساب النهائي.')) return;
            const expiry = window.prompt('تاريخ انتهاء الوثيقة YYYY-MM-DD — اتركه فارغًا إن لم ينطبق', '');
            if (expiry === null) return;
            url = ROUTES.document + '/' + id + '/approve';
            body = expiry.trim() ? {expires_at:expiry.trim()} : {};
        } else if (action === 'doc-reject') {
            if (!current.permissions.review_documents) return;
            const reason = window.prompt('سبب رفض المستند — سيظهر للعميل');
            if (!reason || reason.trim().length < 3) return notice('اكتب سببًا واضحًا للرفض.', 'warning');
            url = ROUTES.document + '/' + id + '/reject';
            body = {reason: reason.trim()};
        } else if (['res-approve','res-reject','res-more'].includes(action)) {
            if (!current.permissions.review_residence || !current.residence_review ||
                Number(current.residence_review.id) !== id) return;
            const status = action === 'res-approve' ? 'verified' :
                (action === 'res-more' ? 'needs_more_evidence' : 'rejected');
            const name = el('vc-res-name') ? el('vc-res-name').value.trim() : '';
            const note = el('vc-res-note') ? el('vc-res-note').value.trim() : '';
            let reason = '';
            if (status === 'verified') {
                if (name.length < 2) return notice('اكتب الاسم كما يظهر في مستند السكن.', 'warning');
                if (!window.confirm('هل تحققت من الدليل والاسم ومحافظة السكن واعتمادها؟')) return;
            } else {
                reason = window.prompt(status === 'rejected' ? 'سبب الرفض للعميل:' : 'ما الدليل الإضافي المطلوب؟') || '';
                if (reason.trim().length < 5) return notice('سبب واضح من خمسة أحرف على الأقل مطلوب.', 'warning');
            }
            url = ROUTES.residence + '/' + id + '/decision';
            body = {status:status,reason:reason.trim(),document_name:name || null,name_review_note:note || null};
        } else if (action === 'ocr-reread' || action === 'ocr-confirm') {
            if (!current.permissions.review_documents || selectedDoc !== id) return;
            if (action === 'ocr-confirm') {
                const fields = {};
                el('vc-ocr').querySelectorAll('[data-ocr-field]').forEach(input => {
                    if (input.value.trim()) fields[input.dataset.ocrField] = input.value.trim();
                });
                if (!window.confirm('إقرار الحقول بعد مراجعة المستند الأصلي وربط رقم الهوية بالحساب؟')) return;
                body = {fields:fields};
                url = ROUTES.document + '/' + id + '/fields';
            } else {
                url = ROUTES.document + '/' + id + '/reread';
                body = {};
            }
        } else if (action === 'account-approve') {
            if (!current.permissions.activate_ready) return;
            const gov = el('vc-governorate') ? el('vc-governorate').value : '';
            if (!gov) return notice('اختر محافظة السكن قبل اعتماد الحساب.', 'warning');
            if (!window.confirm('قرار اعتماد نهائي يغيّر مستوى التوثيق والحدود المالية. هل أكملت المراجعة؟')) return;
            url = ROUTES.accountDecision + '/' + current.account.id + '/kyc';
            body = {status:1, target_tier:current.account.target_tier, governorate:gov};
        } else if (action === 'account-reject') {
            if (!current.permissions.decide_account) return;
            const reason = window.prompt('سبب رفض طلب التوثيق — سيظهر للعميل:') || '';
            if (reason.trim().length < 5) return notice('سبب الرفض مطلوب، خمسة أحرف على الأقل.', 'warning');
            url = ROUTES.accountDecision + '/' + current.account.id + '/kyc';
            body = {status:2, target_tier:current.account.target_tier, reason:reason.trim()};
        } else return;

        button.disabled = true;
        notice('تسجيل القرار والتحقق من الحواجز...', 'info');
        try {
            const result = await req(url, body);
            notice(result.message || 'حُفظ القرار وسُجّل بنجاح.', 'success');
            const previous = current.account.id;
            await loadQueue(true);
            if (previous && Number(chosen) !== Number(previous)) await openCase(previous);
        } catch (error) {
            notice(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    }

    el('vc-list').addEventListener('click', event => {
        const button = event.target.closest('[data-case]');
        if (button) openCase(button.dataset.case);
    });
    el('vc-detail').addEventListener('click', event => {
        const button = event.target.closest('[data-action]');
        if (button) act(button);
    });
    el('vc-refresh').addEventListener('click', () => loadQueue(true));
    el('vc-search-btn').addEventListener('click', () => loadQueue(false));
    el('vc-search').addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); loadQueue(false); }
    });
    el('vc-role').addEventListener('change', event => {
        role = event.target.value; renderList();
    });
    el('vc-filters').addEventListener('click', event => {
        const button = event.target.closest('[data-stage]');
        if (!button) return;
        stage = button.dataset.stage;
        el('vc-filters').querySelectorAll('[data-stage]').forEach(b =>
            b.classList.toggle('active', b === button));
        renderList();
    });
    loadQueue(false);
})();
</script>
@endpush
