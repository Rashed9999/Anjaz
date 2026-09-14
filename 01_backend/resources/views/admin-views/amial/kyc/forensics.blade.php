@extends('layouts.admin.app')

@section('title', 'تتبع تسريب مستندات KYC')

@section('content')
<div class="content container-fluid" dir="rtl">
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <div>
            <h2 class="page-header-title mb-1">مركز تتبّع مستندات الهوية</h2>
            <div class="text-muted">كل مشاهدة آمنة تحمل رمزاً يربط الصورة بالموظف والجلسة ووقت العرض.</div>
        </div>
        <a href="{{ route('admin.amial.kyc.page') }}" class="btn btn-outline-secondary ms-auto">العودة إلى لجنة التحقق</a>
    </div>

    <div class="alert alert-warning">
        <strong>استخدام رقابي فقط.</strong>
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
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

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

    button.addEventListener('click', lookup);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') lookup(); });
})();
</script>
@endsection
