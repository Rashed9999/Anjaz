@extends('layouts.admin.app')
{{-- AMIAL-CHARITY-ADMIN-UI-001: لوحة التبرعات (كان الـAPI كاملاً بلا واجهة) --}}
@section('title', translate('لوحة التبرعات'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:#053391">🎗️ {{ translate('لوحة التبرعات — الجمعيات الخيرية') }}</h4>
    <div class="card border-0 shadow-sm" style="border-radius:16px">
        <div class="card-body">
            <table class="table align-middle" id="orgsTable">
                <thead><tr>
                    <th>{{ translate('الجمعية') }}</th><th>{{ translate('الحالة') }}</th>
                    <th>{{ translate('إجمالي التبرعات') }}</th><th>{{ translate('إجراءات') }}</th>
                </tr></thead>
                <tbody><tr><td colspan="4" class="text-muted">{{ translate('جارٍ التحميل…') }}</td></tr></tbody>
            </table>
        </div>
    </div>
</div>
<script>
const base = '{{ url('admin/amial/charity') }}';
const csrf = '{{ csrf_token() }}';
async function act(ulid, action) {
    if (!confirm('تأكيد الإجراء؟')) return;
    const r = await fetch(`${base}/organizations/${ulid}/${action}`, {method:'POST', headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'}});
    alert(r.ok ? 'تم' : 'فشل الإجراء'); load();
}
async function load() {
    const tb = document.querySelector('#orgsTable tbody');
    try {
        const r = await fetch(`${base}/organizations`, {headers:{'Accept':'application/json'}});
        const j = await r.json();
        const orgs = (j.meta && (j.meta.organizations || j.meta.items)) || j.organizations || [];
        tb.innerHTML = orgs.length ? orgs.map(o => `
            <tr>
                <td><b>${o.name ?? ''}</b><br><small class="text-muted">${o.ulid ?? ''}</small></td>
                <td>${o.status ?? o.verification_status ?? ''}</td>
                <td>${o.total_donations ?? o.total_raised ?? '0'}</td>
                <td>
                    <button class="btn btn-sm btn-success" onclick="act('${o.ulid}','verify')">اعتماد</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="act('${o.ulid}','reject')">رفض</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="act('${o.ulid}','suspend')">تعليق</button>
                </td>
            </tr>`).join('') : '<tr><td colspan="4" class="text-muted">لا جمعيات بعد</td></tr>';
    } catch (e) { tb.innerHTML = '<tr><td colspan="4" class="text-danger">تعذّر التحميل — أعد المحاولة</td></tr>'; }
}
load();
</script>
@endsection
