@extends('layouts.admin.app')

{{--
    AMIAL-KYC-PANEL-001 + AMIAL-KYC-RESTRICTED-QUEUE-001

    طابور KYC العام منفصل فعلياً عن طابور الخصوصية. الحالات المقيدة لا
    تُفلتر في المتصفح؛ الخادم لا يرسلها إلى الطابور العام أصلاً. ومن يملك
    restricted.view يراها هنا في مساحة واضحة، ومن يملك restricted.decide
    وحده يستطيع القرار. لا يُستنتج هذا المسار من جنس صاحب الحساب.
--}}

@section('title', 'مراجعة الهوية')

@section('content')
<div class="content container-fluid" id="kyc-panel" data-testid="kyc-panel">
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <i class="tio-verified text-primary" style="font-size:24px"></i>
        <div>
            <h2 class="page-header-title mb-0">لجنة التحقق والهوية</h2>
            <div class="small text-muted mt-1">المستندات، إثبات ملكية الهوية، الخصوصية، والقرار النهائي في مسار واحد قابل للتدقيق.</div>
        </div>
        <span class="badge bg-warning text-dark" id="kyc-count">0</span>
        <div class="ms-auto d-flex gap-2 flex-wrap">
            @if(auth('user')->user()?->hasPlatformPermission('platform.audit.view'))
                <a class="btn btn-outline-danger btn-sm" href="{{ route('admin.amial.kyc.privacy.page') }}">
                    مركز التتبّع والخصوصية
                </a>
            @endif
            <button class="btn btn-outline-primary btn-sm" id="kyc-btn-refresh" data-testid="kyc-btn-refresh">تحديث</button>
        </div>
    </div>

    <div class="alert alert-secondary py-2 small">
        لا يراجع الموظف مستند نفسه. كل فتح لصورة هوية يصدر <strong>نسخة مشاهدة مائية</strong>
        تحمل الموظف والوقت ورمز VIEW قابلًا للتتبع؛ الأصل المشفر لا يُرسل للمتصفح مباشرة.
        و<strong>اكتمال الورق لا يعني إثبات ملكية الهوية</strong>؛ حالة الإثبات تظهر قبل قرار الحساب.
    </div>

    @if(auth('user')->user()?->hasPlatformPermission('platform.customers.kyc.restricted.view'))
        <div class="card border-danger mb-4" id="kyc-restricted-card" data-testid="kyc-restricted-card">
            <div class="card-header d-flex align-items-center gap-2 flex-wrap">
                <div>
                    <h5 class="card-header-title mb-1">🔒 طابور المراجعة المقيدة</h5>
                    <div class="small text-muted">يظهر فقط للفريق المخول. التعيين بالصلاحية لا بجنس صاحب الحساب.</div>
                </div>
                <span class="badge bg-danger ms-auto" id="kyc-restricted-count">0</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="fw-bold mb-2">مستندات تنتظر المراجعة</div>
                        <div id="kyc-restricted-documents" class="list-group"></div>
                    </div>
                    <div class="col-lg-4">
                        <div class="fw-bold mb-2">مستندات مكتملة / قرار الحساب</div>
                        <div id="kyc-restricted-ready" class="list-group"></div>
                    </div>
                    <div class="col-lg-4">
                        <div class="fw-bold mb-2">طلبات تحقق حضوري</div>
                        <div id="kyc-in-person" class="list-group"></div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="card-header-title">بانتظار المراجعة — الطابور العام</h5></div>
                <div id="kyc-queue" class="list-group list-group-flush" data-testid="kyc-queue"></div>
            </div>
            <div class="card mt-3">
                <div class="card-header d-flex align-items-center">
                    <h5 class="card-header-title mb-0">مستندات مكتملة / قرار الحساب</h5>
                    <span class="badge bg-success ms-auto" id="kyc-activation-count">0</span>
                </div>
                <div class="card-body py-2 small text-muted">
                    زر الاعتماد لا يظهر إلا عندما يكون إثبات ملكية الهوية جاهزاً أيضاً. إن لم يكن، يظهر السبب هنا.
                </div>
                <div id="kyc-activation-queue" class="list-group list-group-flush" data-testid="kyc-activation-queue"></div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card" id="kyc-viewer-card">
                <div class="card-body text-center text-muted py-5" id="kyc-viewer" data-testid="kyc-viewer">
                    <i class="tio-image-outlined" style="font-size:48px;opacity:.3"></i>
                    <div class="mt-2">اختر مستنداً أو حساباً من أحد الطوابير</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/amial/kyc') }}';
    const RESTRICTED_BASE = '{{ url('admin/amial/kyc/restricted') }}';
    const CSRF = '{{ csrf_token() }}';
    const CAN_DECIDE = @json((bool) auth('user')->user()?->hasPlatformPermission('platform.customers.freeze'));
    const CAN_ACTIVATE = @json((bool) auth('user')->user()?->hasPlatformPermission('platform.approvals.decide'));
    const CAN_BIOMETRIC = @json((bool) auth('user')->user()?->hasPlatformPermission('platform.customers.kyc.biometric.view'));
    const CAN_RESTRICTED_VIEW = @json((bool) auth('user')->user()?->hasPlatformPermission('platform.customers.kyc.restricted.view'));
    const CAN_RESTRICTED_DECIDE = @json((bool) auth('user')->user()?->hasPlatformPermission('platform.customers.kyc.restricted.decide'));
    const GOVERNORATES = @json($governorates);
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let current = null;

    async function requestJson(url, options = {}) {
        const r = await fetch(url, {headers: {'Accept': 'application/json', ...(options.headers || {})}, ...options});
        try {
            return await r.json();
        } catch (_) {
            return {success: false, message: `تعذّر قراءة استجابة الخادم (${r.status})`};
        }
    }
    const get = path => requestJson(BASE + path);
    const getAbsolute = url => requestJson(url);
    const post = (path, body) => postAbsolute(BASE + path, body);
    const postAbsolute = (url, body) => requestJson(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
        body: JSON.stringify(body || {}),
    });

    function waitBadge(h) {
        if (Number(h) >= 72) return `<span class="badge bg-danger">${esc(h)} ساعة انتظار</span>`;
        if (Number(h) >= 24) return `<span class="badge bg-warning text-dark">${esc(h)} ساعة</span>`;
        return `<span class="badge bg-light text-dark">${esc(h)} ساعة</span>`;
    }

    function ownershipHtml(o) {
        if (o && o.ready) {
            return '<div class="small text-success mt-1">✓ إثبات ملكية الهوية مكتمل</div>';
        }
        const blockers = (o && o.blockers) || [];
        return `<div class="small text-danger mt-1">${blockers.length
            ? blockers.map(esc).join(' · ')
            : 'إثبات ملكية الهوية غير مكتمل'}</div>`;
    }

    function canDecideCurrent() {
        return CAN_DECIDE && (!current?.restricted || CAN_RESTRICTED_DECIDE);
    }

    document.getElementById('kyc-btn-refresh').onclick = loadQueues;

    async function loadQueue() {
        const box = document.getElementById('kyc-queue');
        box.innerHTML = '<div class="list-group-item text-muted">جارٍ التحميل…</div>';
        const j = await get('/queue');
        if (!j.success) {
            box.innerHTML = `<div class="list-group-item text-danger">${esc(j.message || 'تعذّر التحميل')}</div>`;
            return;
        }

        const q = (j.data && j.data.queue) || [];
        document.getElementById('kyc-count').textContent = q.length;
        box.innerHTML = q.length ? q.map(d => documentRow(d, false)).join('')
            : '<div class="list-group-item text-muted text-center py-4">لا مستندات بانتظار المراجعة</div>';
    }

    function documentRow(d, restricted) {
        return `<button class="list-group-item list-group-item-action js-kyc-open" data-id="${d.id}"
                    data-name="${esc(d.customer_name)}" data-phone="${esc(d.customer_phone)}"
                    data-label="${esc(d.doc_label)}" data-user="${d.user_id}"
                    data-type="${esc(d.doc_type || '')}" data-mime="${esc(d.original_mime || '')}"
                    data-restricted="${restricted ? '1' : '0'}" data-testid="kyc-row-${d.id}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-bold">${esc(d.customer_name)}</div>
                        <div class="small text-muted font-monospace">#${esc(d.user_id)} • ${esc(d.customer_phone)}</div>
                        <div class="small">${esc(d.doc_label)}</div>
                        ${restricted ? '<span class="badge badge-soft-danger mt-1">مراجعة مقيدة</span>' : ''}
                    </div>
                    ${waitBadge(d.waiting_hours)}
                </div>
            </button>`;
    }

    async function loadActivationQueue() {
        const box = document.getElementById('kyc-activation-queue');
        box.innerHTML = '<div class="list-group-item text-muted">جارٍ التحميل…</div>';
        const j = await get('/activation-queue');
        if (!j.success) {
            box.innerHTML = `<div class="list-group-item text-danger">${esc(j.message || 'تعذّر التحميل')}</div>`;
            return;
        }
        const q = (j.data && j.data.queue) || [];
        document.getElementById('kyc-activation-count').textContent = q.length;
        box.innerHTML = q.length ? q.map(a => activationRow(a, false)).join('')
            : '<div class="list-group-item text-muted text-center py-4">لا حسابات تنتظر القرار</div>';
    }

    function activationRow(a, restricted) {
        const ownershipReady = !!(a.ownership && a.ownership.ready);
        const canActivate = CAN_ACTIVATE && ownershipReady && (!restricted || CAN_RESTRICTED_DECIDE);
        return `<div class="list-group-item d-flex justify-content-between align-items-start gap-2">
                <div class="flex-grow-1">
                    <div class="fw-bold">${esc(a.customer_name)}</div>
                    <div class="small text-muted font-monospace">#${esc(a.user_id)} • ${esc(a.customer_phone)}</div>
                    <div class="small text-muted">${a.residence_governorate_name
                        ? 'محافظة محفوظة: ' + esc(a.residence_governorate_name)
                        : 'محافظة السكن مطلوبة'}</div>
                    ${restricted ? '<span class="badge badge-soft-danger mt-1">قرار مقيد</span>' : ''}
                    ${ownershipHtml(a.ownership)}
                </div>
                ${canActivate
                    ? `<button class="btn btn-sm btn-success js-kyc-activate"
                        data-user="${a.user_id}" data-name="${esc(a.customer_name)}"
                        data-governorate="${esc(a.residence_governorate || '')}"
                        data-restricted="${restricted ? '1' : '0'}">اعتماد الحساب</button>`
                    : '<span class="badge badge-soft-secondary">غير جاهز للقرار</span>'}
            </div>`;
    }

    async function loadRestrictedQueue() {
        if (!CAN_RESTRICTED_VIEW) return;
        const docsBox = document.getElementById('kyc-restricted-documents');
        const readyBox = document.getElementById('kyc-restricted-ready');
        const inPersonBox = document.getElementById('kyc-in-person');
        if (!docsBox || !readyBox || !inPersonBox) return;

        docsBox.innerHTML = readyBox.innerHTML = inPersonBox.innerHTML = '<div class="list-group-item text-muted">جارٍ التحميل…</div>';
        const j = await getAbsolute(`${RESTRICTED_BASE}/queue`);
        if (!j.success) {
            const msg = `<div class="list-group-item text-danger">${esc(j.message || 'تعذّر تحميل الطابور المقيد')}</div>`;
            docsBox.innerHTML = readyBox.innerHTML = inPersonBox.innerHTML = msg;
            return;
        }

        const docs = j.data?.documents || [];
        const ready = j.data?.ready_for_account_decision || [];
        const inPerson = j.data?.in_person_requests || [];
        document.getElementById('kyc-restricted-count').textContent = docs.length + ready.length + inPerson.length;

        docsBox.innerHTML = docs.length ? docs.map(d => documentRow(d, true)).join('')
            : '<div class="list-group-item text-muted">لا مستندات مقيدة تنتظر.</div>';
        readyBox.innerHTML = ready.length ? ready.map(a => activationRow(a, true)).join('')
            : '<div class="list-group-item text-muted">لا حسابات مقيدة تنتظر القرار.</div>';
        inPersonBox.innerHTML = inPerson.length ? inPerson.map(inPersonRow).join('')
            : '<div class="list-group-item text-muted">لا طلبات تحقق حضوري.</div>';
    }

    function inPersonRow(a) {
        return `<div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-bold">${esc(a.customer_name)}</div>
                    <div class="small text-muted font-monospace">#${esc(a.user_id)} • ${esc(a.customer_phone)}</div>
                    <div class="small">${esc(a.privacy?.review_mode_label || 'تحقق حضوري')}</div>
                    ${ownershipHtml(a.ownership)}
                </div>
                ${CAN_RESTRICTED_DECIDE
                    ? `<button class="btn btn-sm btn-outline-danger js-kyc-in-person" data-user="${a.user_id}" data-name="${esc(a.customer_name)}">تسجيل المقابلة</button>`
                    : '<span class="badge badge-soft-secondary">عرض فقط</span>'}
            </div>
        </div>`;
    }

    async function loadQueues() {
        const jobs = [loadQueue(), loadActivationQueue()];
        if (CAN_RESTRICTED_VIEW) jobs.push(loadRestrictedQueue());
        await Promise.all(jobs);
    }

    document.addEventListener('click', function (e) {
        const b = e.target.closest('.js-kyc-open');
        if (b) {
            current = {
                id: b.dataset.id, name: b.dataset.name, phone: b.dataset.phone,
                label: b.dataset.label, user: b.dataset.user, type: b.dataset.type,
                mime: b.dataset.mime, restricted: b.dataset.restricted === '1',
            };
            openDoc();
            return;
        }
        const activate = e.target.closest('.js-kyc-activate');
        if (activate) {
            openActivation(activate);
            return;
        }
        const inPerson = e.target.closest('.js-kyc-in-person');
        if (inPerson) verifyInPerson(inPerson);
    });

    async function verifyInPerson(button) {
        if (!CAN_RESTRICTED_DECIDE) return;
        const reason = prompt(`مرجع/سبب إثبات المقابلة الحضورية للعميل ${button.dataset.name}:\n\nلا تكتب بيانات الهوية الخام هنا.`);
        if (!reason || reason.trim().length < 5) {
            if (reason !== null) alert('اكتب مرجعاً واضحاً من 5 أحرف على الأقل.');
            return;
        }
        button.disabled = true;
        const j = await postAbsolute(`${RESTRICTED_BASE}/users/${encodeURIComponent(button.dataset.user)}/in-person-verify`, {reason: reason.trim()});
        alert(j.message || (j.success ? 'سُجل إثبات الملكية الحضوري.' : 'تعذر تسجيل الإثبات.'));
        button.disabled = false;
        if (j.success) loadRestrictedQueue();
    }

    function openActivation(button) {
        const userId = button.dataset.user;
        const name = button.dataset.name;
        const restricted = button.dataset.restricted === '1';
        if (restricted && !CAN_RESTRICTED_DECIDE) return;
        const storedGovernorate = button.dataset.governorate || '';
        const options = GOVERNORATES.map(g => `<option value="${esc(g.code)}" ${g.code === storedGovernorate ? 'selected' : ''}>${esc(g.name)}</option>`).join('');

        current = {user: userId, name, restricted};
        document.getElementById('kyc-viewer').innerHTML = `
            <div class="text-end">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h5 class="mb-0">اعتماد وتفعيل الحساب</h5>
                    ${restricted ? '<span class="badge bg-danger">قرار مقيد</span>' : ''}
                </div>
                <p class="text-muted mb-3">العميل: <strong>${esc(name)}</strong> <span class="font-monospace">#${esc(userId)}</span></p>
                <div class="alert alert-info text-start small">
                    الخادم سيعيد فحص اكتمال المستندات، انتهاء الهوية، التكرار، وإثبات ملكية الهوية لحظة القرار؛ الواجهة لا تستطيع تجاوز هذه الحواجز.
                </div>
                <label class="form-label d-block text-start" for="kyc-governorate">محافظة السكن</label>
                <select class="form-select" id="kyc-governorate">
                    <option value="">اختر المحافظة كما تظهر في الملف</option>${options}
                </select>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button class="btn btn-outline-secondary" id="kyc-activate-cancel">إلغاء</button>
                    <button class="btn btn-success" id="kyc-activate-confirm">اعتماد وتفعيل</button>
                </div>
            </div>`;

        document.getElementById('kyc-activate-cancel').onclick = () => clearViewer('اختر مستنداً أو حساباً من أحد الطوابير');
        document.getElementById('kyc-activate-confirm').onclick = async function () {
            const governorate = document.getElementById('kyc-governorate').value;
            if (!governorate) { alert('محافظة السكن مطلوبة لتفعيل التحويلات.'); return; }
            this.disabled = true;
            this.textContent = 'جارٍ الاعتماد…';
            const j = await post(`/users/${encodeURIComponent(userId)}/activate`, {target_tier: 2, governorate});
            if (!j.success) {
                alert(j.message || 'تعذّر اعتماد الحساب');
                this.disabled = false;
                this.textContent = 'اعتماد وتفعيل';
                return;
            }
            clearViewer(j.message || 'تم اعتماد الحساب');
            loadQueues();
        };
    }

    function openDoc() {
        const reason = current.restricted ? 'مراجعة طابور KYC المقيد' : 'مراجعة طابور الهوية';
        const fileUrl = `${BASE}/documents/${encodeURIComponent(current.id)}/file?reason=${encodeURIComponent(reason)}`;
        const isImage = (current.mime || '').startsWith('image/');
        const biometricBlocked = current.type === 'selfie' && !CAN_BIOMETRIC;
        const canDecide = canDecideCurrent();

        document.getElementById('kyc-viewer').innerHTML = `
            <div class="text-end">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="text-start">
                        <h5 class="mb-0">${esc(current.name)}</h5>
                        <div class="small text-muted font-monospace">#${esc(current.user)} • ${esc(current.phone)}</div>
                        <span class="badge badge-soft-primary mt-1">${esc(current.label)}</span>
                        <span class="badge badge-soft-danger mt-1">معاينة مائية قابلة للتتبّع</span>
                        ${current.restricted ? '<span class="badge bg-danger mt-1">خصوصية مقيدة</span>' : ''}
                    </div>
                    ${canDecide ? `<div class="d-flex gap-2">
                        <button class="btn btn-outline-danger" id="kyc-reject">رفض</button>
                        <button class="btn btn-success" id="kyc-approve">اعتماد</button>
                    </div>` : '<span class="badge badge-soft-secondary">قراءة فقط</span>'}
                </div>
                ${biometricBlocked
                    ? `<div class="alert alert-danger text-start"><strong>صورة الوجه محمية.</strong> لا تملك صلاحية الصور البيومترية؛ لا تُرسل الصورة إليك.</div>`
                    : (isImage
                        ? `<img id="kyc-document-image" src="${fileUrl}" alt="${esc(current.label)}" style="width:100%;max-height:420px;object-fit:contain;border:1px solid #ddd;border-radius:8px;background:#fafafa">`
                        : `<iframe src="${fileUrl}" style="width:100%;height:420px;border:1px solid #ddd;border-radius:8px;background:#fafafa" title="مستند الهوية"></iframe>`)}
                <div id="kyc-preview-fallback" class="alert alert-warning small mt-2 d-none">
                    تعذّرت المعاينة الآمنة. لا يرسل أميال الملف الأصلي بلا علامة مائية؛ أعد رفعه بصيغة مدعومة.
                </div>
                <div id="kyc-ocr" class="mt-3"></div>
                <div id="kyc-completeness" class="mt-3"></div>
            </div>`;

        if (canDecide) {
            document.getElementById('kyc-approve').onclick = approve;
            document.getElementById('kyc-reject').onclick = reject;
        }
        const image = document.getElementById('kyc-document-image');
        if (image) image.onerror = () => document.getElementById('kyc-preview-fallback')?.classList.remove('d-none');
        loadOcr();
    }

    const FIELD_LABELS = {
        full_name: 'الاسم', national_id: 'الرقم الوطني', date_of_birth: 'تاريخ الميلاد',
        expiry_date: 'تاريخ الانتهاء', gender: 'الجنس', country: 'الدولة',
    };
    const OCR_STATUS = {
        not_run: ['secondary', 'لم تُقرأ بعد'], success: ['success', 'قُرئت'],
        low_confidence: ['warning text-dark', 'ثقة منخفضة — الحقول لم تُملأ عمداً'],
        failed: ['danger', 'تعذّرت القراءة'], unavailable: ['dark', 'محرّك القراءة غير متاح على الخادم'],
    };

    async function loadOcr() {
        const box = document.getElementById('kyc-ocr');
        if (!box || !current?.id) return;
        box.innerHTML = '<div class="text-muted small">جارٍ قراءة الحقول…</div>';
        const j = await get(`/documents/${current.id}/ocr`);
        if (!j.success) {
            box.innerHTML = `<div class="alert alert-danger small">${esc(j.message || 'لا تملك صلاحية عرض بيانات OCR لهذا المستند.')}</div>`;
            return;
        }
        const o = j.data;
        if (o.applicable === false) {
            box.innerHTML = `<div class="alert alert-info mb-0"><strong>لا يوجد استخراج حقول لهذا المستند.</strong><div class="small mt-1">${esc(o.not_applicable_reason || '')}</div></div>`;
            return;
        }

        const canDecide = canDecideCurrent();
        const st = OCR_STATUS[o.status] || OCR_STATUS.not_run;
        const findings = (o.findings || []).map(f => `<div class="alert alert-${f.severity === 'critical' ? 'danger' : (f.severity === 'warning' ? 'warning' : 'secondary')} py-2 small mb-2">${esc(f.message)}</div>`).join('');
        const rows = Object.keys(FIELD_LABELS).map(k => {
            const f = o.fields[k];
            const v = (o.verified && o.verified[k]) || (f ? f.value : '');
            const hint = f && !f.certain ? '<span class="badge bg-warning text-dark ms-1">استُنتج — تحقّق</span>' : '';
            return `<div class="col-md-6 mb-2"><label class="form-label small mb-1">${FIELD_LABELS[k]} ${hint}</label>
                <input type="text" class="form-control form-control-sm js-ocr-field" data-field="${k}" value="${esc(v)}" ${canDecide ? '' : 'readonly'}></div>`;
        }).join('');

        box.innerHTML = `<div class="card"><div class="card-header d-flex align-items-center gap-2 py-2">
                <strong class="small">الحقول المستخرجة</strong><span class="badge bg-${st[0]}">${st[1]}</span>
                ${o.confidence ? `<span class="small text-muted">الثقة ${esc(o.confidence)}٪</span>` : ''}
                ${canDecide ? '<button class="btn btn-sm btn-outline-secondary ms-auto" id="kyc-reread">إعادة القراءة</button>' : ''}
            </div><div class="card-body">${findings}
                <div class="alert alert-secondary py-2 small">المحرّك <strong>يقترح ولا يقرّر</strong>. رقم الهوية الذي تقرّه هنا يصبح الرقم القانوني المشفر للحساب ويُفحص ضد التكرار.</div>
                <div class="row">${rows}</div>
                <div class="d-flex gap-2 align-items-center mt-2">
                    ${canDecide ? '<button class="btn btn-sm btn-primary" id="kyc-confirm-fields">إقرار الحقول وربط الهوية</button>' : '<span class="small text-muted">عرض فقط</span>'}
                    ${o.raw_text ? '<button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#kyc-raw">النص الخام</button>' : ''}
                </div>
                ${o.raw_text ? `<div class="collapse mt-2" id="kyc-raw"><pre class="bg-light p-2 small mb-0" style="max-height:160px;overflow:auto;white-space:pre-wrap">${esc(o.raw_text)}</pre></div>` : ''}
            </div></div>`;

        if (canDecide) {
            document.getElementById('kyc-reread').onclick = async () => { await post(`/documents/${current.id}/reread`, {}); loadOcr(); };
            document.getElementById('kyc-confirm-fields').onclick = async () => {
                const fields = {};
                document.querySelectorAll('.js-ocr-field').forEach(i => { if (i.value.trim()) fields[i.dataset.field] = i.value.trim(); });
                const r = await post(`/documents/${current.id}/fields`, {fields});
                alert(r.message || (r.success ? 'أُقرّت الحقول وربط رقم الهوية بالحساب.' : 'فشل'));
                if (r.success) loadQueues();
            };
        }
    }

    function clearViewer(msg) {
        current = null;
        document.getElementById('kyc-viewer').innerHTML = `<div class="text-center text-muted py-5"><i class="tio-checkmark-circle-outlined" style="font-size:48px;opacity:.3"></i><div class="mt-2">${esc(msg)}</div></div>`;
    }

    async function approve() {
        if (!canDecideCurrent()) return;
        if (!confirm(`اعتماد «${current.label}» للعميل ${current.name}؟\n\nاعتماد المستند لا يعتمد الحساب النهائي.`)) return;
        const exp = prompt('تاريخ انتهاء الوثيقة (YYYY-MM-DD) — اتركه فارغاً إن لم تكن تنتهي:') || null;
        const j = await post(`/documents/${current.id}/approve`, exp ? {expires_at: exp} : {});
        if (!j.success) { alert(j.message || 'فشل الاعتماد'); return; }
        loadQueues();
        clearViewer('اعتُمد المستند؛ سيُعاد حساب إثبات الملكية وحالة القرار.');
    }

    async function reject() {
        if (!canDecideCurrent()) return;
        const reason = prompt('سبب الرفض (إلزامي — يُعرض للعميل):');
        if (!reason || reason.trim().length < 3) { if (reason !== null) alert('سبب الرفض إلزامي'); return; }
        const j = await post(`/documents/${current.id}/reject`, {reason: reason.trim()});
        if (!j.success) { alert(j.message || 'فشل الرفض'); return; }
        loadQueues();
        clearViewer('رُفض المستند وأُبلغ العميل بالسبب.');
    }

    loadQueues();
})();
</script>
@endsection
