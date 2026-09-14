@extends('layouts.admin.app')

@section('title', 'خصوصية وتتبع مستندات KYC')

@section('content')
<div class="content container-fluid" dir="rtl">
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <div>
            <h2 class="page-header-title mb-1">مركز خصوصية وتتبّع الهوية</h2>
            <div class="text-muted">مسار الخصوصية، حالة إثبات صاحب الهوية، وتتبع كل نسخة مشاهدة إلى الموظف.</div>
        </div>
        <a href="{{ route('admin.amial.kyc.page') }}" class="btn btn-outline-secondary ms-auto">العودة إلى لجنة التحقق</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <div>
                        <h5 class="card-header-title mb-1">حالات الخصوصية وإثبات صاحب الهوية</h5>
                        <div class="small text-muted">لا تُحوّل «غير مربوط» إلى صفر أو نجاح؛ الحالة تُعرض كما هي.</div>
                    </div>
                    <button id="privacy-refresh" class="btn btn-sm btn-outline-primary ms-auto">تحديث</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead><tr>
                            <th>الحساب</th><th>مسار المراجعة</th><th>إثبات الملكية</th><th>Liveness</th><th>Face Match</th>
                        </tr></thead>
                        <tbody id="privacy-cases">
                            <tr><td colspan="5" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-header-title mb-0">المزوّد البيومتري — Runtime</h5></div>
                <div class="card-body small">
                    <div class="alert alert-info" id="biometric-readiness">حالة المزود البيومتري: جارٍ الفحص…</div>

                    <div id="biometric-runtime" class="row g-2">
                        <div class="col-6"><div class="border rounded p-2 h-100"><div class="text-muted">المزوّد</div><div id="bio-provider" class="fw-bold font-monospace">—</div></div></div>
                        <div class="col-6"><div class="border rounded p-2 h-100"><div class="text-muted">Driver</div><div id="bio-driver" class="fw-bold">—</div></div></div>
                        <div class="col-6"><div class="border rounded p-2 h-100"><div class="text-muted">قيد التنفيذ</div><div id="bio-pending" class="fs-5 fw-bold">0</div></div></div>
                        <div class="col-6"><div class="border rounded p-2 h-100"><div class="text-muted">مكتمل</div><div id="bio-completed" class="fs-5 fw-bold">0</div></div></div>
                        <div class="col-6"><div class="border rounded p-2 h-100"><div class="text-muted">مراجعة بشرية</div><div id="bio-manual" class="fs-5 fw-bold">0</div></div></div>
                        <div class="col-6"><div class="border rounded p-2 h-100"><div class="text-muted">فشل</div><div id="bio-failed" class="fs-5 fw-bold">0</div></div></div>
                        <div class="col-12"><div class="border rounded p-2"><div class="text-muted">آخر Callback معالج</div><div id="bio-last-callback" class="font-monospace">—</div></div></div>
                    </div>

                    <div class="alert alert-light border mt-3 mb-3" id="bio-data-policy">
                        لا تُخزَّن صور/فيديو بيومترية ولا نصوص callbacks الخام في أميال.
                    </div>
                    <div class="mb-2"><strong>خصوصية إضافية:</strong> صورة الوجه محمية بصلاحية بيومترية مستقلة، وكل مشاهدة مائية ومسجلة.</div>
                    <div class="mb-2"><strong>تحقق آلي خاص:</strong> النتيجة الناجحة تصبح دليل ملكية فقط؛ اعتماد الحساب النهائي يبقى قرار لجنة التحقق.</div>
                    <div><strong>تحقق حضوري:</strong> لا يُعد اعتماداً بمجرد اختيار المسار؛ يحتاج قرار مراجع مخول.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-warning">
        <strong>تتبّع تسريب صورة.</strong>
        أدخل رمز <span class="font-monospace">VIEW</span> الظاهر على الصورة المسرّبة، مثل
        <span class="font-monospace">AM-0123ABCDEF456789ABCD</span>. لا تُعرض هنا صورة الهوية نفسها.
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <label class="form-label" for="trace-code">رمز المشاهدة</label>
            <div class="input-group">
                <input id="trace-code" class="form-control font-monospace" maxlength="23"
                       placeholder="AM-XXXXXXXXXXXXXXXXXXXX" autocomplete="off">
                <button id="trace-search" class="btn btn-primary">تتبّع</button>
            </div>
            <div id="trace-result" class="mt-3"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="card-header-title mb-0">آخر المشاهدات المعلّمة</h5></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr>
                    <th>الرمز</th><th>الموظف</th><th>المستند</th><th>صاحب الملف</th><th>الوقت</th><th>IP</th>
                </tr></thead>
                <tbody>
                @forelse($recent as $row)
                    <tr>
                        <td class="font-monospace small">{{ $row->trace_code }}</td>
                        <td>#{{ $row->actor_user_id }} — {{ trim(($row->actor_first_name ?? '').' '.($row->actor_last_name ?? '')) ?: 'غير معروف' }}</td>
                        <td>#{{ $row->document_id }} · {{ $row->doc_type }}</td>
                        <td>#{{ $row->subject_user_id }}</td>
                        <td class="font-monospace small">{{ $row->viewed_at }}</td>
                        <td class="font-monospace small">{{ $row->ip_address ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">لا توجد مشاهدات معلّمة بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const input = document.getElementById('trace-code');
    const button = document.getElementById('trace-search');
    const result = document.getElementById('trace-result');
    const endpoint = @json(route('admin.amial.kyc.privacy.trace'));
    const casesEndpoint = @json(route('admin.amial.kyc.privacy.cases'));
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value == null || value === '' ? '—' : String(value);
    };

    const statusBadge = (kind, status, score) => {
        const labels = {
            not_configured: 'غير مربوط', pending: 'قيد التنفيذ', passed: 'اجتاز', failed: 'فشل',
            manual_review: 'مراجعة بشرية', matched: 'متطابق', not_matched: 'غير متطابق',
            redacted: 'محجوب بالصلاحية'
        };
        const cls = ['passed', 'matched'].includes(status) ? 'success'
            : ['failed', 'not_matched'].includes(status) ? 'danger'
            : ['not_configured', 'redacted'].includes(status) ? 'secondary' : 'warning text-dark';
        const scoreText = score == null ? '' : ` · ${esc(score)}`;
        return `<span class="badge bg-${cls}">${esc(labels[status] || status)}${scoreText}</span>`;
    };

    const paintRuntime = runtime => {
        const readiness = document.getElementById('biometric-readiness');
        const attempts = runtime?.attempts || {};
        const configured = Boolean(runtime?.configured);

        readiness.className = configured ? 'alert alert-success' : 'alert alert-warning';
        readiness.textContent = configured
            ? 'المزوّد البيومتري جاهز فعلياً: Driver مسجل، التفعيل قائم، ومفاتيح التشغيل/التوقيع متاحة.'
            : runtime?.enabled
                ? 'التفعيل مطلوب لكن المزود غير جاهز فعلياً؛ لن يفتح أميال التحقق الآلي حتى يصبح Driver متاحاً.'
                : 'التحقق البيومتري غير مفعّل حالياً؛ لا توجد درجات Liveness أو Face Match وهمية.';

        setText('bio-provider', runtime?.provider || 'غير مربوط');
        setText('bio-driver', runtime?.driver_registered ? (runtime?.available ? 'جاهز' : 'مسجل / غير متاح') : 'غير مسجل');
        setText('bio-pending', attempts.pending ?? 0);
        setText('bio-completed', attempts.completed ?? 0);
        setText('bio-manual', attempts.manual_review ?? 0);
        setText('bio-failed', attempts.failed ?? 0);
        setText('bio-last-callback', runtime?.last_callback_at || 'لا يوجد');
        setText('bio-data-policy', runtime?.data_policy || 'لا تُخزَّن صور/فيديو بيومترية ولا نصوص callbacks الخام في أميال.');
    };

    async function loadPrivacyCases() {
        const body = document.getElementById('privacy-cases');
        const readiness = document.getElementById('biometric-readiness');
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        try {
            const r = await fetch(casesEndpoint, {headers: {'Accept': 'application/json'}});
            const j = await r.json();
            if (!j.success) {
                body.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">${esc(j.message || 'تعذّر التحميل')}</td></tr>`;
                readiness.className = 'alert alert-danger';
                readiness.textContent = j.message || 'تعذّر فحص المزود البيومتري';
                return;
            }

            paintRuntime(j.data.biometric_runtime || {});
            const rows = j.data.cases || [];
            body.innerHTML = rows.length ? rows.map(c => `
                <tr>
                    <td><strong>${esc(c.name)}</strong><div class="small text-muted font-monospace">${c.user_id == null ? '' : '#'+esc(c.user_id)+' · '}${esc(c.phone)}</div></td>
                    <td>${c.restricted_review ? '<span class="badge bg-danger">مقيدة</span> ' : ''}${esc(c.review_mode_label)}</td>
                    <td class="small">${esc(c.ownership_method_label)}</td>
                    <td>${statusBadge('liveness', c.liveness.status, c.liveness.score)}</td>
                    <td>${statusBadge('face', c.face_match.status, c.face_match.score)}</td>
                </tr>`).join('')
                : '<tr><td colspan="5" class="text-center text-muted py-4">لا توجد حالات خصوصية مسجلة بعد. تظهر عند فتح شاشة الخصوصية أو اختيار مسار.</td></tr>';
        } catch (_) {
            body.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">تعذّر الاتصال بمركز الخصوصية.</td></tr>';
        }
    }

    async function lookup() {
        const trace = input.value.trim().toUpperCase();
        if (!/^AM-[A-F0-9]{20}$/.test(trace)) {
            result.innerHTML = '<div class="alert alert-danger mb-0">رمز المشاهدة غير صحيح.</div>';
            return;
        }
        button.disabled = true;
        result.innerHTML = '<div class="text-muted">جارٍ البحث…</div>';
        try {
            const r = await fetch(`${endpoint}?trace=${encodeURIComponent(trace)}`, {headers: {'Accept': 'application/json'}});
            const j = await r.json();
            if (!j.success) {
                result.innerHTML = `<div class="alert alert-danger mb-0">${esc(j.message || 'لم يُعثر على الرمز')}</div>`;
                return;
            }
            const d = j.data;
            result.innerHTML = `
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white"><strong>تم تحديد مصدر نسخة المشاهدة</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4"><div class="text-muted small">الموظف</div><div class="fw-bold">#${esc(d.employee.id)} — ${esc(d.employee.name || 'غير معروف')}</div></div>
                            <div class="col-md-4"><div class="text-muted small">وقت المشاهدة</div><div class="font-monospace">${esc(d.viewed_at)}</div></div>
                            <div class="col-md-4"><div class="text-muted small">IP</div><div class="font-monospace">${esc(d.ip_address)}</div></div>
                            <div class="col-md-4"><div class="text-muted small">صاحب ملف KYC</div><div>#${esc(d.subject_user_id)}</div></div>
                            <div class="col-md-4"><div class="text-muted small">المستند</div><div>#${esc(d.document_id)} · ${esc(d.doc_type)}</div></div>
                            <div class="col-md-4"><div class="text-muted small">نسخة العلامة</div><div>${esc(d.watermark_version)}</div></div>
                            <div class="col-12"><div class="text-muted small">سبب الوصول</div><div>${esc(d.access_reason)}</div></div>
                            <div class="col-12"><div class="text-muted small">User-Agent</div><div class="font-monospace small text-break">${esc(d.user_agent)}</div></div>
                        </div>
                    </div>
                </div>`;
        } catch (_) {
            result.innerHTML = '<div class="alert alert-danger mb-0">تعذّر الاتصال بمركز التتبّع.</div>';
        } finally {
            button.disabled = false;
        }
    }

    document.getElementById('privacy-refresh').addEventListener('click', loadPrivacyCases);
    button.addEventListener('click', lookup);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') lookup(); });
    loadPrivacyCases();
})();
</script>
@endsection