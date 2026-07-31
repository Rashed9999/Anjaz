@extends('layouts.admin.app')

{{--
    AMIAL-KYC-PANEL-001 — طابور مراجعة مستندات الهوية.

    الطرف الناقص من دائرةٍ قيل إنها اكتملت: العميل صار يرفع، والمستند يُخزَّن
    مشفَّراً، و`queue`/`file`/`approve`/`reject` كلّها مبنيّة — ومسجَّلة على
    سطح الـAPI وحده. فلا مراجعَ يملك شاشةً يفتح فيها ما وصل.

    ثلاثة قرارات في هذه الشاشة:

    • **الصورة تُعرَض في إطارٍ لا تُفتح في تبويب.** فتحُها في تبويبٍ يتركها في
      سجلّ المتصفّح وفي ذاكرته؛ والإطار مع `no-store` من الخادم يجعلها تزول
      بزوال الصفحة.

    • **الاعتماد لا يرفع المستوى.** تُعرَض الاكتمالية بعد كلّ اعتماد ليعرف
      المراجع ما بقي، ويبقى رفعُ المستوى قراراً منفصلاً — وإلّا صار اعتمادُ
      صورةٍ واحدة توسيعاً لحدود المال بلا أن ينوي أحد.

    • **الانتظار مُلوَّن.** طابورٌ فيه عشرة مستندات عمرها ساعة ليس كطابورٍ فيه
      عشرة عمرها أسبوع، والعدد وحده لا يفرّق بينهما.
--}}

@section('title', 'مراجعة الهوية')

@section('content')
<div class="content container-fluid" id="kyc-panel" data-testid="kyc-panel">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-verified text-primary" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">مراجعة مستندات الهوية</h2>
        <span class="badge bg-warning text-dark" id="kyc-count">0</span>
        <button class="btn btn-outline-primary btn-sm ms-auto" id="kyc-btn-refresh" data-testid="kyc-btn-refresh">تحديث</button>
    </div>

    <div class="alert alert-secondary py-2 small">
        لا يراجع الموظّف مستندَ نفسه — والنظام يرفض ذلك ولو حاول.
        كلّ فتحٍ لصورة هويّة يُسجَّل باسم من فتحه.
    </div>

    <div class="row g-3">
        {{-- الطابور --}}
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="card-header-title">بانتظار المراجعة</h5></div>
                <div id="kyc-queue" class="list-group list-group-flush" data-testid="kyc-queue"></div>
            </div>
        </div>

        {{-- المستند المفتوح --}}
        <div class="col-lg-7">
            <div class="card" id="kyc-viewer-card">
                <div class="card-body text-center text-muted py-5" id="kyc-viewer" data-testid="kyc-viewer">
                    <i class="tio-image-outlined" style="font-size:48px;opacity:.3"></i>
                    <div class="mt-2">اختر مستنداً من الطابور لعرضه</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/amial/kyc') }}';
    const CSRF = '{{ csrf_token() }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let current = null;

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

    function waitBadge(h) {
        if (h >= 72) return `<span class="badge bg-danger">${h} ساعة انتظار</span>`;
        if (h >= 24) return `<span class="badge bg-warning text-dark">${h} ساعة</span>`;
        return `<span class="badge bg-light text-dark">${h} ساعة</span>`;
    }

    // ---------- الطابور ----------
    document.getElementById('kyc-btn-refresh').onclick = loadQueue;

    async function loadQueue() {
        const box = document.getElementById('kyc-queue');
        box.innerHTML = '<div class="list-group-item text-muted">جارٍ التحميل…</div>';
        const j = await get('/queue');
        if (!j.success) { box.innerHTML = `<div class="list-group-item text-danger">${esc(j.message || 'تعذّر التحميل')}</div>`; return; }

        const q = (j.data && j.data.queue) || [];
        document.getElementById('kyc-count').textContent = q.length;

        box.innerHTML = q.length ? q.map(d => `
            <button class="list-group-item list-group-item-action js-kyc-open" data-id="${d.id}"
                    data-name="${esc(d.customer_name)}" data-phone="${esc(d.customer_phone)}"
                    data-label="${esc(d.doc_label)}" data-user="${d.user_id}"
                    data-testid="kyc-row-${d.id}">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-bold">${esc(d.customer_name)}</div>
                        <div class="small text-muted font-monospace">#${d.user_id} • ${esc(d.customer_phone)}</div>
                        <div class="small">${esc(d.doc_label)}</div>
                    </div>
                    ${waitBadge(d.waiting_hours)}
                </div>
            </button>`).join('')
            : '<div class="list-group-item text-muted text-center py-4">لا مستندات بانتظار المراجعة</div>';
    }

    // ---------- عرض المستند ----------
    document.addEventListener('click', function (e) {
        const b = e.target.closest('.js-kyc-open');
        if (!b) return;
        current = {id: b.dataset.id, name: b.dataset.name, phone: b.dataset.phone,
                   label: b.dataset.label, user: b.dataset.user};
        openDoc();
    });

    function openDoc() {
        const reason = 'مراجعة طابور الهوية';
        // في إطارٍ لا في تبويب — انظر شرح أعلى الملفّ.
        document.getElementById('kyc-viewer').innerHTML = `
            <div class="text-end">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="text-start">
                        <h5 class="mb-0">${esc(current.name)}</h5>
                        <div class="small text-muted font-monospace">#${esc(current.user)} • ${esc(current.phone)}</div>
                        <span class="badge badge-soft-primary mt-1">${esc(current.label)}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-danger" id="kyc-reject" data-testid="kyc-reject">رفض</button>
                        <button class="btn btn-success" id="kyc-approve" data-testid="kyc-approve">اعتماد</button>
                    </div>
                </div>
                <iframe src="${BASE}/documents/${encodeURIComponent(current.id)}/file?reason=${encodeURIComponent(reason)}"
                        style="width:100%;height:520px;border:1px solid #ddd;border-radius:8px;background:#fafafa"
                        title="مستند الهوية"></iframe>
                <div id="kyc-completeness" class="mt-3"></div>
            </div>`;

        document.getElementById('kyc-approve').onclick = approve;
        document.getElementById('kyc-reject').onclick = reject;
    }

    function clearViewer(msg) {
        current = null;
        document.getElementById('kyc-viewer').innerHTML = `
            <div class="text-center text-muted py-5">
                <i class="tio-checkmark-circle-outlined" style="font-size:48px;opacity:.3"></i>
                <div class="mt-2">${esc(msg)}</div>
            </div>`;
    }

    async function approve() {
        if (!confirm(`اعتماد «${current.label}» للعميل ${current.name}؟\n\nالاعتماد لا يرفع مستوى الحساب بنفسه — يبقى ذلك قراراً منفصلاً.`)) return;
        const exp = prompt('تاريخ انتهاء الوثيقة (YYYY-MM-DD) — اتركه فارغاً إن لم تكن تنتهي:') || null;
        const j = await post(`/documents/${current.id}/approve`, exp ? {expires_at: exp} : {});
        if (!j.success) { alert(j.message || 'فشل الاعتماد'); return; }

        // ما بقي على العميل يُقال هنا، لا في شاشةٍ أخرى يفتحها المراجع لاحقاً.
        const c = j.data && j.data.completeness;
        const name = current.name;
        loadQueue();
        clearViewer('اعتُمد المستند');
        if (c) {
            document.getElementById('kyc-viewer').insertAdjacentHTML('beforeend', c.complete
                ? `<div class="alert alert-success mx-4">اكتملت مستندات ${esc(name)} للفئة ${c.tier} — يبقى رفع المستوى قراراً منفصلاً.</div>`
                : `<div class="alert alert-warning mx-4">ما زال ينقص ${esc(name)}: ${c.missing.map(esc).join('، ')}</div>`);
        }
    }

    async function reject() {
        const reason = prompt('سبب الرفض (إلزامي — يُعرَض للعميل ليعرف ما يُصلحه):');
        if (!reason || reason.trim().length < 3) { alert('سبب الرفض إلزامي'); return; }
        const j = await post(`/documents/${current.id}/reject`, {reason: reason.trim()});
        if (!j.success) { alert(j.message || 'فشل الرفض'); return; }
        loadQueue();
        clearViewer('رُفض المستند وأُبلغ العميل بالسبب');
    }

    loadQueue();
})();
</script>
@endsection
