@extends('layouts.admin.app')

@section('title', 'إثبات الإقامة — KYC')

@section('content')
<div class="content container-fluid" dir="rtl">
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <div>
            <h2 class="page-header-title mb-1">إثبات الإقامة ونطاق التشغيل</h2>
            <div class="text-muted">الأهلية التشغيلية تعتمد على محل الإقامة الموثق فقط؛ محافظة الأصل لا تمنح ولا تمنع الخدمة.</div>
        </div>
        <a href="{{ route('admin.amial.kyc.page') }}" class="btn btn-outline-secondary ms-auto">العودة إلى لجنة التحقق</a>
    </div>

    <div class="alert alert-info mb-4">
        <strong>سياسة أميال:</strong>
        فاتورة الكهرباء أو الماء ليست إلزامية. نقبل أدلة متعددة مثل عقد الإيجار، عقد خدمة منزلية،
        خطاب جهة عمل/دراسة، أو فاتورة شراء/توصيل حديثة يظهر فيها <strong>اسم العميل وعنوان التسليم</strong>.
        عنوان المتجر وحده لا يثبت السكن، وإفادة مالك العقار دليل مساعد لا يعتمد منفرداً.
        <br>
        <strong>الاسم إلزامي:</strong> قبل الاعتماد يؤكد المراجع الاسم الظاهر في دليل السكن،
        ويقارنه النظام بالاسم القانوني الرباعي الذي صرّح به العميل عند التسجيل.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card h-100"><div class="card-body">
            <div class="text-muted small">بانتظار المراجعة</div><div id="count-pending" class="fs-2 fw-bold">—</div>
        </div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body">
            <div class="text-muted small">حالات خصوصية محجوبة عن هذا الموظف</div><div id="count-hidden" class="fs-2 fw-bold">—</div>
        </div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body">
            <div class="text-muted small">قاعدة القرار</div><div class="fw-bold mt-2">Verified Residence → Operational Zone</div>
        </div></div></div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center">
            <div>
                <h5 class="card-header-title mb-1">طلبات إثبات السكن</h5>
                <div class="small text-muted">كل مستند هو نسخة KYC محمية؛ فتحه يمر من صلاحيات العرض والعلامة المائية الجنائية.</div>
            </div>
            <button id="refresh" class="btn btn-sm btn-outline-primary ms-auto">تحديث</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>العميل</th><th>مطابقة الاسم</th><th>الإقامة المعلنة</th><th>الدليل</th><th>القوة</th><th>النطاق</th><th>المستند</th><th>القرار</th>
                </tr></thead>
                <tbody id="rows"><tr><td colspan="8" class="text-center text-muted py-5">جارٍ التحميل…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(() => {
    const queueUrl = @json(route('admin.amial.kyc.residence.queue'));
    const decisionBase = @json(url('/admin/amial/kyc/residence'));
    const documentBase = @json(url('/admin/amial/kyc/documents'));
    const csrf = @json(csrf_token());
    const body = document.getElementById('rows');

    const esc = value => String(value ?? '—').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const strength = s => ({strong:'قوي', medium:'متوسط', supporting:'مساعد فقط'}[s] || s);
    const matchLabel = s => ({exact:'تطابق كامل', strong:'تطابق قوي', partial:'تطابق جزئي', mismatch:'اختلاف جوهري', unavailable:'غير متاح'}[s] || 'لم يُقارن');
    const matchClass = s => ({exact:'bg-success', strong:'bg-success', partial:'bg-warning text-dark', mismatch:'bg-danger'}[s] || 'bg-secondary');

    async function load() {
        body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5">جارٍ التحميل…</td></tr>';
        const response = await fetch(queueUrl, {headers:{'Accept':'application/json'}});
        if (!response.ok) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-5">تعذر تحميل طابور إثبات السكن.</td></tr>';
            return;
        }
        const payload = await response.json();
        const rows = payload.data || [];
        document.getElementById('count-pending').textContent = rows.length;
        document.getElementById('count-hidden').textContent = payload.meta?.restricted_hidden ?? 0;

        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5">لا توجد طلبات سكن معلقة.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(row => `
            <tr>
                <td><div class="fw-bold">${esc(row.name)}</div><div class="small text-muted">${esc(row.phone)} · #${row.user_id}</div></td>
                <td>
                    <div class="small text-muted mb-1">المصرّح به</div>
                    <div class="fw-bold mb-2">${esc(row.declared_legal_name)}</div>
                    ${row.ocr_name_suggestion ? `
                        <div class="small text-muted">اقتراح OCR</div>
                        <div class="small mb-1">${esc(row.ocr_name_suggestion)}</div>
                        <span class="badge ${matchClass(row.ocr_name_match_status)}">
                            ${esc(matchLabel(row.ocr_name_match_status))}
                            ${row.ocr_name_match_score != null ? ' · ' + esc(row.ocr_name_match_score) + '%' : ''}
                        </span>
                    ` : '<div class="small text-muted mb-1">OCR لم يستخرج اسماً — اقرأ المستند يدوياً.</div>'}
                    <input class="form-control form-control-sm mt-2"
                           data-document-name="${row.id}"
                           value="${esc(row.document_name || row.ocr_name_suggestion || '')}"
                           placeholder="الاسم كما يظهر في إثبات السكن">
                    <input class="form-control form-control-sm mt-2"
                           data-name-note="${row.id}"
                           value="${esc(row.name_review_note || '')}"
                           placeholder="ملاحظة مطابقة — مطلوبة عند التطابق الجزئي">
                </td>
                <td>${esc(row.governorate_name)}<div class="small font-monospace text-muted">${esc(row.governorate)}</div></td>
                <td>${esc(row.evidence_label)}<div class="small text-muted">${esc(row.evidence_date || 'تاريخ غير مدخل')}</div></td>
                <td><span class="badge ${row.strength === 'strong' ? 'bg-success' : (row.strength === 'medium' ? 'bg-primary' : 'bg-warning text-dark')}">${esc(strength(row.strength))}</span></td>
                <td>${row.operational ? '<span class="badge bg-success">داخل نطاق التشغيل</span>' : '<span class="badge bg-secondary">خارج النطاق الحالي</span>'}</td>
                <td>${row.kyc_document_id ? `<a target="_blank" rel="noopener" class="btn btn-sm btn-outline-dark" href="${documentBase}/${row.kyc_document_id}/file">عرض آمن</a>` : '—'}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-success" data-action="verified" data-id="${row.id}">اعتماد السكن</button>
                    <button class="btn btn-sm btn-outline-warning" data-action="needs_more_evidence" data-id="${row.id}">دليل إضافي</button>
                    <button class="btn btn-sm btn-outline-danger" data-action="rejected" data-id="${row.id}">رفض</button>
                </td>
            </tr>`).join('');
    }

    async function decide(id, status) {
        let reason = '';
        if (status !== 'verified') {
            reason = window.prompt(status === 'rejected' ? 'سبب الرفض:' : 'ما الدليل الإضافي المطلوب؟', '') ?? '';
            if (reason.trim().length < 5) return;
        }
        let documentName = '';
        let nameReviewNote = '';
        if (status === 'verified') {
            documentName = document.querySelector(`[data-document-name="${id}"]`)?.value?.trim() || '';
            nameReviewNote = document.querySelector(`[data-name-note="${id}"]`)?.value?.trim() || '';
            if (documentName.length < 2) {
                alert('اكتب الاسم كما يظهر في إثبات السكن قبل الاعتماد.');
                return;
            }
            if (!window.confirm(
                'سيُقارن الاسم الذي أكدته بالاسم القانوني المصرّح به. ' +
                'الاختلاف الجوهري يمنع الاعتماد. متابعة؟'
            )) return;
        }

        const response = await fetch(`${decisionBase}/${id}/decision`, {
            method: 'POST',
            headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
            body: JSON.stringify({
                status,
                reason,
                document_name: documentName || null,
                name_review_note: nameReviewNote || null,
            }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            alert(payload.message || 'تعذر تسجيل القرار.');
            return;
        }
        await load();
    }

    body.addEventListener('click', event => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        decide(button.dataset.id, button.dataset.action);
    });
    document.getElementById('refresh').addEventListener('click', load);
    load();
})();
</script>
@endsection
