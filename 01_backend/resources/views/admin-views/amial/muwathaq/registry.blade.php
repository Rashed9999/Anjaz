@extends('layouts.admin.app')

@section('title', 'موثّق — سجلّ كل الحسابات')

@section('content')
@php($muwathaqUrl = rtrim(trim((string) config('muwathaq.base_url')), '/'))
<div class="content container-fluid" dir="rtl">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h4 class="mb-1">موثّق — سجلّ كل الحسابات</h4>
                <p class="text-muted mb-0">
                    عملاء وتجّار ووكلاء، بكل حالاتهم: المستوى والحالة والمحافظة والرصيد والمرجع وحالة الأدلة.
                    <strong>الوثائق نفسها لا تُنسخ إلى أميال باي</strong> — موثّق يملك الدليل، فزرّ «الوثائق في موثّق» يفتح القضية هناك
                    حيث تُراقَب المشاهدة وتُوسم بعلامة فالكون.
                </p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-primary" href="{{ route('admin.amial.hub.muwathaq-openings.index') }}">📋 الطلبات المعلّقة</a>
                @if ($muwathaqUrl !== '')
                    <a class="btn btn-primary" href="{{ $muwathaqUrl }}" target="_blank" rel="noopener">🔐 نظام موثّق</a>
                @else
                    <span class="text-danger small align-self-center">عنوان موثّق غير مضبوط في MUWATHAQ_BASE_URL.</span>
                @endif
            </div>
        </div>
        <div class="card-body">
            <form id="registry-filters" class="row g-2 mb-3">
                <div class="col-sm-3"><input class="form-control" name="q" placeholder="بحث: اسم · هاتف · بريد" autocomplete="off"></div>
                <div class="col-sm-2">
                    <select class="form-select" name="kind">
                        <option value="">كل الأنواع</option><option value="customer">عميل</option><option value="merchant">تاجر</option><option value="agent">وكيل</option>
                    </select>
                </div>
                <div class="col-sm-2">
                    <select class="form-select" name="status">
                        <option value="">كل الحالات</option>
                        <option value="submitted">جديد</option><option value="under_review">قيد المراجعة</option><option value="needs_more_info">بانتظار استكمال</option>
                        <option value="approved">معتمد</option><option value="rejected">مرفوض</option>
                    </select>
                </div>
                <div class="col-sm-2">
                    <select class="form-select" name="tier">
                        <option value="">كل المستويات</option><option value="1">المستوى 1</option><option value="2">المستوى 2</option><option value="3">المستوى 3</option>
                    </select>
                </div>
                <div class="col-sm-3 d-grid"><button class="btn btn-primary" type="submit">عرض</button></div>
            </form>
            <div id="registry" class="table-responsive">جارٍ التحميل…</div>
            <div id="registry-pager" class="d-flex justify-content-between align-items-center mt-3 small text-muted"></div>
        </div>
    </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
  const root = document.getElementById('registry');
  const pager = document.getElementById('registry-pager');
  const form = document.getElementById('registry-filters');
  const listUrl = @json(route('admin.amial.hub.muwathaq-accounts.list'));
  const esc = (v) => String(v ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const KIND = {customer:'عميل', merchant:'تاجر', agent:'وكيل'};
  const STATUS = {submitted:['جديد','warning text-dark'], under_review:['قيد المراجعة','warning text-dark'], needs_more_info:['بانتظار استكمال','warning text-dark'],
                  awaiting_admin_approval:['بانتظار الإدارة','info'], approved:['معتمد','success'], rejected:['مرفوض','danger']};
  const EVIDENCE = {submitted:'وصل', approved:'مُراجَع', rejected:'مرفوض', pending:'بانتظار الوصول'};
  const money = (v) => Number(v || 0).toLocaleString('en-US') + ' ر.ي';
  let page = 1;

  async function load() {
    const params = new URLSearchParams(new FormData(form));
    params.set('page', page);
    root.textContent = 'جارٍ التحميل…';
    try {
      const r = await fetch(listUrl + '?' + params.toString(), {headers: {Accept: 'application/json'}});
      if (!r.ok) throw new Error('تعذّر تحميل السجل (' + r.status + ').');
      const {data = [], meta = {}} = await r.json();
      if (!data.length) {
        root.innerHTML = '<div class="alert alert-info mb-0">لا حسابات بهذه المعايير. (لا بيانات ≠ صفر مؤكَّد: غيّر الفلاتر.)</div>';
        pager.textContent = '';
        return;
      }
      root.innerHTML = '<table class="table table-hover align-middle"><thead><tr><th>صاحب الحساب</th><th>النوع</th><th>المستوى</th><th>الحالة</th><th>السكن والرصيد</th><th>القرار</th><th>الأدلة</th><th></th></tr></thead><tbody>'
        + data.map(row => {
          const u = row.user || {};
          const st = STATUS[row.status] || [row.status, 'secondary'];
          const kind = (KIND[row.subject_kind] || row.subject_kind) + (row.business_type ? ' · ' + row.business_type : '');
          const acct = u.id
            ? '<span class="badge bg-' + (u.active ? 'success' : 'secondary') + '">' + (u.active ? 'نشط' : 'مجمَّد') + '</span> '
              + (u.kyc_verified ? '<span class="badge bg-primary">موثَّق</span>' : '<span class="badge bg-light text-dark">غير موثَّق</span>')
            : '<span class="text-muted small">لا حساب بعد</span>';
          const ev = Object.entries(row.evidence_states || {}).map(([k, n]) => esc((EVIDENCE[k] || k) + ' ' + n)).join(' · ') || '—';
          return '<tr>'
            + '<td><strong>' + esc(u.name) + '</strong><br><span class="text-muted small" dir="ltr">' + esc(u.phone) + '</span><br><span class="text-muted small">' + esc(u.email) + '</span></td>'
            + '<td>' + esc(kind) + '<br><span class="text-muted small">' + esc(row.verification_path) + '</span></td>'
            + '<td>' + (row.requested_tier ? 'المطلوب ' + esc(row.requested_tier) : '—') + '<br><span class="small text-muted">الحالي ' + esc(u.tier ?? '—') + '</span></td>'
            + '<td><span class="badge bg-' + st[1] + '">' + esc(st[0]) + '</span><br>' + acct + '</td>'
            + '<td>' + esc(u.governorate || 'غير محدد') + '<br><span class="small text-muted">' + esc(u.zone) + '</span><br><span dir="ltr">' + esc(money(u.balance)) + '</span></td>'
            + '<td class="small"><span dir="ltr">' + esc(row.decision_reference) + '</span><br>' + esc(row.decided_at || row.submitted_at) + '</td>'
            + '<td class="small">' + ev + '</td>'
            + '<td class="text-nowrap">'
            + (u.account_url ? '<a class="btn btn-sm btn-outline-primary mb-1" href="' + esc(u.account_url) + '">ملف الحساب</a><br>' : '')
            + (row.muwathaq_url ? '<a class="btn btn-sm btn-outline-dark" href="' + esc(row.muwathaq_url) + '" target="_blank" rel="noopener">الوثائق في موثّق ↗</a>' : '')
            + '</td></tr>';
        }).join('') + '</tbody></table>';
      pager.innerHTML = '<span>صفحة ' + esc(meta.page) + ' من ' + esc(meta.last_page) + ' — ' + esc(meta.total) + ' حساباً</span>'
        + '<span><button class="btn btn-sm btn-outline-secondary" id="pg-prev"' + (meta.page <= 1 ? ' disabled' : '') + '>السابق</button> '
        + '<button class="btn btn-sm btn-outline-secondary" id="pg-next"' + (meta.page >= meta.last_page ? ' disabled' : '') + '>التالي</button></span>';
      document.getElementById('pg-prev').onclick = () => { page = Math.max(1, page - 1); load(); };
      document.getElementById('pg-next').onclick = () => { page = page + 1; load(); };
    } catch (e) {
      root.innerHTML = '<div class="alert alert-danger mb-0">' + esc(e.message || 'تعذّر تحميل السجل.') + '</div>';
      pager.textContent = '';
    }
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); page = 1; load(); });
  load();
})();
</script>
@endpush
