@extends('layouts.admin.app')
@section('title', 'سجل ملفات فتح الحسابات')
@section('content')
<div class="content container-fluid" id="registration-dossiers">
    <div class="card mb-3"><div class="card-body">
        <h2 class="mb-1">سجل ملفات فتح الحسابات</h2>
        <p class="text-muted mb-0">أرشيف قراءة فقط. يبدأ فتح العميل أو المنشأة من «مركز العملاء» أو «مركز التجّار»؛ لا توجد شاشة إنشاء ثانية هنا.</p>
    </div></div>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>المرجع</th><th>الملف</th><th>الحساب</th><th>الحالة</th><th>أُنشئ بواسطة</th><th>التاريخ</th><th>الأرشيف</th></tr></thead>
        <tbody id="dossiers-body"><tr><td colspan="7" class="text-center text-muted py-4">جارٍ التحميل…</td></tr></tbody>
    </table></div></div>
</div>
@endsection
@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => { const root='{{ url('admin/amial/registration-dossiers') }}', body=document.getElementById('dossiers-body'), esc=s=>String(s??'—').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
fetch(root+'/index',{headers:{Accept:'application/json'}}).then(r=>r.json()).then(j=>{ const rows=j.data||[]; body.innerHTML=rows.length?rows.map(d=>`<tr><td class="font-monospace small">${esc(d.reference)}</td><td>${d.type==='merchant'?'منشأة':'عميل'}</td><td>${d.subject_user_id?`#${d.subject_user_id}`:'—'}</td><td>${esc(d.state)}</td><td>${esc(d.creator)}</td><td class="small">${esc(d.created_at)}</td><td><a class="btn btn-sm btn-outline-primary" target="_blank" href="${root}/${encodeURIComponent(d.reference)}/pdf">طباعة PDF</a>${d.has_paper_form?` <a class="btn btn-sm btn-outline-secondary" target="_blank" href="${root}/${encodeURIComponent(d.reference)}/paper">النسخة الموقعة</a>`:''}</td></tr>`).join(''):'<tr><td colspan="7" class="text-center text-muted py-4">لا ملفات بعد</td></tr>'; }).catch(()=>body.innerHTML='<tr><td colspan="7" class="text-center text-danger py-4">تعذر تحميل الأرشيف</td></tr>'); })();
</script>
@endpush
