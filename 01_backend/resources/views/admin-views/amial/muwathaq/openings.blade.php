@extends('layouts.admin.app')

@section('title', 'موثّق — الحسابات الجديدة')

@section('content')
@php($muwathaqUrl = rtrim(trim((string) config('muwathaq.base_url')), '/'))
<div class="content container-fluid" dir="rtl">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h4 class="mb-1">موثّق — الحسابات الجديدة</h4>
                <p class="text-muted mb-0">عرض تفاصيل الطلبات في أميال باي. قرار الاعتماد أو الرفض يتم حصراً في نظام موثّق.</p>
            </div>
            @if ($muwathaqUrl !== '')
                <a class="btn btn-primary" href="{{ $muwathaqUrl }}" target="_blank" rel="noopener">
                    🔐 الدخول إلى نظام موثّق
                </a>
            @else
                <span class="text-danger small">عنوان موثّق غير مضبوط في MUWATHAQ_BASE_URL.</span>
            @endif
        </div>
        <div class="card-body">
            <div id="muwathaq-openings" class="table-responsive">جارٍ تحميل الحسابات الجديدة…</div>
        </div>
    </div>
</div>
@endsection

@push('script_2')
<script>
(async function () {
  const root = document.getElementById('muwathaq-openings');
  const escape = (v) => String(v ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const labels = {submitted:'جديد', under_review:'قيد المراجعة', needs_more_info:'بانتظار استكمال'};

  try {
    const response = await fetch(@json(route('admin.amial.hub.muwathaq-openings.list')), {headers:{Accept:'application/json'}});
    if (!response.ok) throw new Error('تعذر تحميل الحسابات.');
    const {data=[]} = await response.json();

    if (!data.length) {
      root.innerHTML = '<div class="alert alert-info mb-0">لا توجد حسابات جديدة في انتظار قرار موثّق.</div>';
      return;
    }

    root.innerHTML = '<table class="table table-hover align-middle"><thead><tr><th>صاحب الحساب</th><th>النوع</th><th>الحالة</th><th>وقت الطلب</th><th>التفاصيل</th></tr></thead><tbody>'
      + data.map(row => {
        const applicant = row.applicant || {};
        const evidence = (row.evidence || []).map(e => '<li>'+escape(e.kind)+' — '+escape(e.state)+'</li>').join('') || '<li>لا توجد أدلة مسجلة بعد.</li>';
        const kind = row.subject_kind + (row.business_type ? ' · '+row.business_type : '') + (row.requested_tier ? ' · مستوى '+row.requested_tier : '');
        return '<tr><td><strong>'+escape(applicant.name || applicant.phone)+'</strong><br><span class="text-muted small">'+escape(applicant.phone)+' · '+escape(applicant.email)+'</span></td>'
          + '<td>'+escape(kind)+'</td><td><span class="badge bg-warning text-dark">'+escape(labels[row.status] || row.status)+'</span></td>'
          + '<td class="small">'+escape(row.submitted_at)+'</td><td><details><summary class="text-primary" style="cursor:pointer">عرض التفاصيل</summary>'
          + '<div class="border rounded p-2 mt-2 small"><div><b>رقم الطلب:</b> '+escape(row.application_ulid)+'</div><div><b>مسار التحقق:</b> '+escape(row.verification_path)+'</div><div class="mt-1"><b>حالة الأدلة:</b><ul class="mb-0">'+evidence+'</ul></div></div></details></td></tr>';
      }).join('') + '</tbody></table>';
  } catch (error) {
    root.innerHTML = '<div class="alert alert-danger mb-0">'+escape(error.message || 'تعذر تحميل الحسابات الجديدة.')+'</div>';
  }
})();
</script>
@endpush
