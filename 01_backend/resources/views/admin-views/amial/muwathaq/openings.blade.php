@extends('layouts.admin.app')

@section('title', 'اعتمادات موثّق')

@section('content')
<div class="content container-fluid">
    <div class="card">
        <div class="card-header"><h4 class="mb-0">اعتمادات موثّق النهائية</h4></div>
        <div class="card-body">
            <p class="text-muted">موثّق يتحقق من الأدلة. اعتماد هذه الصفحة وحده هو الذي يفعّل الحساب وينشئ المحفظة. لا تُعرض صور الهوية هنا.</p>
            @if ($errors->any()) <div class="alert alert-danger">{{ $errors->first() }}</div> @endif
            @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
            <div id="muwathaq-openings" class="table-responsive">جارٍ تحميل الطلبات…</div>
        </div>
    </div>
</div>
@endsection

@push('script_2')
<script>
(async function () {
  const root = document.getElementById('muwathaq-openings');
  const escape = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  try {
    const response = await fetch(@json(route('admin.muwathaq-openings.list')), {headers:{Accept:'application/json'}});
    const {data=[]} = await response.json();
    if (!data.length) { root.innerHTML='<div class="alert alert-info mb-0">لا توجد طلبات بانتظار اعتماد الإدارة.</div>'; return; }
    root.innerHTML = '<table class="table table-hover"><thead><tr><th>الطلب</th><th>صاحب الحساب</th><th>المسار</th><th>قرار موثّق</th><th>الأدلة</th><th>قرار الإدارة</th></tr></thead><tbody>' + data.map(row => {
      const evidence = row.evidence.map(e => escape(e.kind)+(e.document_received?' ✓':'')).join(' · ');
      const approve = @json(url('/admin/amial/muwathaq-openings'))+'/'+encodeURIComponent(row.application_ulid)+'/approve';
      const reject = @json(url('/admin/amial/muwathaq-openings'))+'/'+encodeURIComponent(row.application_ulid)+'/reject';
      return '<tr><td>'+escape(row.application_ulid)+'</td><td>'+escape(row.applicant.name || row.applicant.phone)+'</td><td>'+escape(row.verification_path)+' '+escape(row.requested_tier ? 'Tier '+row.requested_tier : '')+'</td><td>'+escape(row.muwathaq_reference)+'</td><td>'+evidence+'</td><td><form class="d-inline" method="post" action="'+approve+'">@csrf<button class="btn btn-success btn-sm" onclick="return confirm(\'سيُفعّل الحساب ويُنشئ المحفظة. متابعة؟\')">اعتماد</button></form> <form class="d-inline" method="post" action="'+reject+'">@csrf<input type="hidden" name="reason" value="رفض الإدارة بعد مراجعة تشغيلية"><button class="btn btn-danger btn-sm" onclick="return confirm(\'رفض التفعيل؟\')">رفض</button></form></td></tr>';
    }).join('')+'</tbody></table>';
  } catch (_) { root.innerHTML='<div class="alert alert-danger mb-0">تعذر تحميل طابور اعتمادات موثّق.</div>'; }
})();
</script>
@endpush
