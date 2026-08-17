@extends('layouts.admin.app')

@section('title', 'كتالوج المنتجات')

@section('content')
{{--
    AMIAL-CATALOG-001 — «مفتوحٌ بمراجعة»، والمراجعة هي هذه الشاشة.

    التجّار يقترحون والإدارة توثّق. وبلا هذه الشاشة يصير «مفتوحاً» بلا
    «مراجعة»: كتالوج يمتلئ بأسماء متضاربة وأخطاء إملاء ولا أحد يحسمها.

    ولا سعر هنا ولا كمية — والجدول نفسه لا يحمل عموداً لهما.
--}}

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">لوحة التحكم</a></li>
    <li class="breadcrumb-item active">كتالوج المنتجات</li>
  </ol>
</nav>

<div class="alert alert-light border small" id="cat-why">
  <strong>كيف يمتلئ الكتالوج؟</strong>
  التاجر يُدخل صنفاً بباركود مجهول فيصير <strong>مقترَحاً</strong> يراه من بعده،
  وأنت توثّقه أو ترفضه أو تصحّح اسمه. والباركود الداخليّ (يبدأ بـ٢) لا يدخل
  إطلاقاً — معناه يختلف من متجر لآخر، فإدخاله يُفسد الكتالوج.
</div>

<div class="row g-3 mb-4" id="cat-kpis">
  @foreach ([
    ['total',           'إجمالي الأصناف',   'primary'],
    ['verified',        'موثّقة',           'success'],
    ['proposed',        'بانتظار المراجعة', 'warning'],
    ['conflicts',       'تعارض أسماء',      'danger'],
    ['verified_rate',   'نسبة التوثيق',     'info'],
    ['added_this_week', 'أُضيف هذا الأسبوع','secondary'],
  ] as [$key, $label, $color])
  <div class="col-md">
    <div class="card stat-card h-100">
      <div class="card-body">
        <div class="text-muted small">{{ $label }}</div>
        <div class="h4 mb-0 text-{{ $color }}" data-kpi="{{ $key }}">…</div>
      </div>
    </div>
  </div>
  @endforeach
</div>

<div class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-end">
    <div>
      <label class="form-label small mb-1">بحث</label>
      <input id="cat-search" class="form-control form-control-sm"
             placeholder="باركود · اسم · تصنيف" style="min-width:220px">
    </div>
    <div>
      <label class="form-label small mb-1">الحالة</label>
      <select id="cat-status" class="form-select form-select-sm">
        <option value="">الكل</option>
        <option value="proposed">مقترَحة</option>
        <option value="verified">موثّقة</option>
        <option value="rejected">مرفوضة</option>
      </select>
    </div>
    <div class="form-check ms-2">
      <input class="form-check-input" type="checkbox" id="cat-conflicts">
      <label class="form-check-label small" for="cat-conflicts">التعارضات فقط</label>
    </div>
    <button id="cat-refresh" class="btn btn-sm btn-outline-secondary">تحديث</button>
    <button id="cat-add" class="btn btn-sm btn-primary">إضافة صنف</button>
    <button id="cat-import" class="btn btn-sm btn-outline-primary">استيراد CSV</button>
    <a id="cat-export" class="btn btn-sm btn-outline-success"
       href="{{ route('admin.amial.catalog.export') }}">تصدير CSV</a>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>الباركود</th>
          <th><a href="#" data-sort="name">الاسم</a></th>
          <th>التصنيف</th>
          <th><a href="#" data-sort="adoption_count">تبنّاه</a></th>
          <th>الحالة</th>
          <th>اقترحه</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="cat-rows">
        <tr><td colspan="7" class="text-muted p-4">…</td></tr>
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="small text-muted" id="cat-count">—</span>
    <div class="btn-group btn-group-sm">
      <button id="cat-prev" class="btn btn-outline-secondary">السابق</button>
      <button id="cat-next" class="btn btn-outline-secondary">التالي</button>
    </div>
  </div>
</div>

{{-- تفصيل + مراجعة --}}
<div class="modal fade" id="cat-detail" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">مراجعة الصنف</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="cat-detail-body">…</div>
      <div class="modal-footer">
        <span class="text-danger small me-auto" id="cat-review-err"></span>
        <button class="btn btn-sm btn-outline-danger" id="cat-reject">رفض</button>
        <button class="btn btn-sm btn-success" id="cat-verify">توثيق</button>
      </div>
    </div>
  </div>
</div>

{{-- إضافة --}}
<div class="modal fade" id="cat-add-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">إضافة صنف</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label small">الباركود</label>
          <input id="cat-new-barcode" class="form-control form-control-sm" inputmode="numeric"></div>
        <div class="mb-2"><label class="form-label small">الاسم</label>
          <input id="cat-new-name" class="form-control form-control-sm"></div>
        <div class="row g-2">
          <div class="col"><label class="form-label small">التصنيف</label>
            <input id="cat-new-category" class="form-control form-control-sm"></div>
          <div class="col"><label class="form-label small">الوحدة</label>
            <input id="cat-new-unit" class="form-control form-control-sm" placeholder="قطعة · كيلو"></div>
        </div>
        {{-- AMIAL-CATALOG-IMAGE-001 — **من الجهاز، مصغَّرةً قبل الحفظ.**
             و`image_path` كان عموداً يُقرأ في موضعين ولا يُكتب في موضع. --}}
        <div class="mt-2">
          <label class="form-label small">صورة الصنف</label>
          <input type="file" id="cat-new-image" class="form-control form-control-sm"
                 accept="image/jpeg,image/png,image/webp" data-testid="cat-image-file">
          <div class="form-text">تُصغَّر إلى ٤٠٠ بكسل — صورةٌ واحدةٌ لكلّ باركود يشترك فيها كلُّ التجّار.</div>
          <div class="mt-2" id="cat-new-image-preview"></div>
        </div>
        <div class="text-danger small mt-2" id="cat-add-err"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">تراجع</button>
        <button class="btn btn-sm btn-primary" id="cat-add-go">حفظ موثّقاً</button>
      </div>
    </div>
  </div>
</div>

{{-- استيراد --}}
<div class="modal fade" id="cat-import-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">استيراد CSV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="small text-muted">
          الأعمدة بالترتيب: <code>barcode, name, category, unit</code>.
          الباركود الداخليّ وغير المعياريّ يُتخطّى — <strong>ويُقال كم ولماذا.</strong>
        </p>
        <input type="file" id="cat-import-file" class="form-control form-control-sm" accept=".csv,text/csv">
        <div class="mt-3 small" id="cat-import-result"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">إغلاق</button>
        <button class="btn btn-sm btn-primary" id="cat-import-go">استورد</button>
      </div>
    </div>
  </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
  'use strict';

  var page = 1, sort = 'created_at', dir = 'desc', currentId = null;

  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  };

  var BADGE = { verified:'success', proposed:'warning', rejected:'secondary' };
  var LABEL = { verified:'موثّقة', proposed:'مقترَحة', rejected:'مرفوضة' };
  var CSRF = document.querySelector('meta[name="csrf-token"]').content;
  // **مُعرَّفٌ قبل أوّل استعمال.** متغيّرٌ غيرُ معرَّفٍ داخل `map` يرمي
  // فيموت المعالجُ صامتاً ويبقى الجدولُ على «جارٍ التحميل». (القاعدة ٩.)
  var IMG_BASE = '{{ asset('storage') }}/';

  function get(u) { return fetch(u, { headers:{'Accept':'application/json'} }).then(function (r) { return r.json(); }); }

  function post(u, body) {
    return fetch(u, {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function loadStats() {
    get('{{ route('admin.amial.catalog.stats') }}').then(function (j) {
      var m = (j && j.meta) || {};
      document.querySelectorAll('[data-kpi]').forEach(function (el) {
        var k = el.getAttribute('data-kpi'), v = m[k];
        // «لا أصناف» ليست «٠٪ توثيق». الغياب يُقال.
        if (k === 'verified_rate') {
          el.textContent = (v === null || v === undefined) ? 'لا أصناف بعد' : v + '٪';
          return;
        }
        el.textContent = (v === null || v === undefined) ? '—' : v;
      });
    });
  }

  function query() {
    return '?page=' + page + '&sort=' + sort + '&dir=' + dir
      + '&search=' + encodeURIComponent($('cat-search').value.trim())
      + '&status=' + encodeURIComponent($('cat-status').value)
      + '&only_conflicts=' + ($('cat-conflicts').checked ? '1' : '0');
  }

  function loadRows() {
    get('{{ route('admin.amial.catalog.rows') }}' + query()).then(function (j) {
      var m = (j && j.meta) || {}, rows = m.rows || [];

      $('cat-rows').innerHTML = rows.length ? rows.map(function (r) {
        return '<tr>'
          + '<td><code>' + esc(r.barcode) + '</code></td>'
          + '<td>' + (r.image_path
              ? '<img src="' + esc(IMG_BASE + r.image_path) + '" alt="" loading="lazy"'
                + ' style="width:32px;height:32px;object-fit:cover;border-radius:4px" class="ms-2">'
              : '') + esc(r.name) + '</td>'
          + '<td class="small text-muted">' + esc(r.category || '—') + '</td>'
          + '<td>' + r.adoption_count + '</td>'
          + '<td><span class="badge bg-' + (BADGE[r.status] || 'secondary') + '">'
          + esc(LABEL[r.status] || r.status) + '</span></td>'
          + '<td class="small text-muted">' + esc(r.proposer) + '</td>'
          + '<td><button class="btn btn-sm btn-outline-primary" data-act="show" data-id="' + r.id + '">مراجعة</button></td>'
          + '</tr>';
      }).join('') : '<tr><td colspan="7" class="text-muted p-4">لا أصناف مطابقة</td></tr>';

      $('cat-count').textContent = 'الإجمالي ' + (m.total || 0) + ' · صفحة ' + (m.page || 1) + ' من ' + (m.last_page || 1);
      $('cat-prev').disabled = (m.page || 1) <= 1;
      $('cat-next').disabled = (m.page || 1) >= (m.last_page || 1);
    });
  }

  function showDetail(id) {
    currentId = id;
    $('cat-review-err').textContent = '';
    $('cat-detail-body').innerHTML = '<div class="text-muted">…</div>';
    new bootstrap.Modal($('cat-detail')).show();

    get('{{ url('admin/amial/catalog') }}/' + id).then(function (j) {
      if (!j || !j.success) { $('cat-detail-body').innerHTML = '<div class="text-danger">تعذّر الجلب</div>'; return; }

      var e = j.meta.entry, sug = j.meta.suggestions || [], distinct = j.meta.distinct_names || 0;

      var html = '<div class="mb-3"><code class="fs-6">' + esc(e.barcode) + '</code>'
        + ' <span class="badge bg-' + (BADGE[e.status] || 'secondary') + '">'
        + esc(LABEL[e.status] || e.status) + '</span>'
        + ' <span class="text-muted small">تبنّاه ' + e.adoption_count + ' تاجراً</span></div>';

      html += '<div class="row g-2 mb-3">'
        + '<div class="col-12"><label class="form-label small">الاسم المعتمد</label>'
        + '<input id="cat-edit-name" class="form-control form-control-sm" value="' + esc(e.name) + '"></div>'
        + '<div class="col"><label class="form-label small">التصنيف</label>'
        + '<input id="cat-edit-category" class="form-control form-control-sm" value="' + esc(e.category || '') + '"></div>'
        + '<div class="col"><label class="form-label small">الوحدة</label>'
        + '<input id="cat-edit-unit" class="form-control form-control-sm" value="' + esc(e.unit || '') + '"></div>'
        + '<div class="col-12"><label class="form-label small">ملاحظة المراجعة</label>'
        + '<input id="cat-edit-note" class="form-control form-control-sm"></div>'
        + '</div>';

      // **التعارض يُعرض ولا يُبتلع.**
      if (distinct > 1) {
        html += '<div class="alert alert-warning small py-2">⚠ هذا الباركود فيه <strong>'
          + distinct + '</strong> أسماء مختلفة. اختر الاسم المعتمد.</div>';
      }

      html += '<div class="small text-muted mb-1">ما أرسله التجّار</div>'
        + '<table class="table table-sm"><tbody>' + sug.map(function (s) {
          return '<tr><td>' + esc(s.name) + '</td>'
            + '<td class="small text-muted">' + esc(s.merchant) + '</td>'
            + '<td><button class="btn btn-sm btn-link p-0" data-pick="' + esc(s.name) + '">اعتمد هذا</button></td></tr>';
        }).join('') + '</tbody></table>';

      $('cat-detail-body').innerHTML = html;
    });
  }

  function review(action) {
    $('cat-review-err').textContent = '';
    post('{{ url('admin/amial/catalog') }}/' + currentId + '/review', {
      action: action,
      name: ($('cat-edit-name') || {}).value || '',
      category: ($('cat-edit-category') || {}).value || '',
      unit: ($('cat-edit-unit') || {}).value || '',
      note: ($('cat-edit-note') || {}).value || ''
    }).then(function (j) {
      if (!j || !j.success) { $('cat-review-err').textContent = (j && j.message) || 'تعذّر الحفظ'; return; }
      bootstrap.Modal.getInstance($('cat-detail')).hide();
      loadRows(); loadStats();
    });
  }

  // تفويضٌ على الجسم — والصفّ لا يحمل data-act فلا تُبتلع الضغطة.
  $('cat-rows').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]');
    if (!b) return;
    e.preventDefault();
    showDetail(b.getAttribute('data-id'));
  });

  $('cat-detail-body').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-pick]');
    if (!b) return;
    e.preventDefault();
    if ($('cat-edit-name')) $('cat-edit-name').value = b.getAttribute('data-pick');
  });

  $('cat-verify').addEventListener('click', function () { review('verify'); });
  $('cat-reject').addEventListener('click', function () { review('reject'); });

  $('cat-add').addEventListener('click', function () {
    $('cat-add-err').textContent = '';
    ['cat-new-barcode','cat-new-name','cat-new-category','cat-new-unit'].forEach(function (i) { $(i).value = ''; });
    new bootstrap.Modal($('cat-add-modal')).show();
  });

  // **الرفعُ خارج `post()`**: تلك تُرسل JSON، و`FormData` يجب أن يضع
  // المتصفّحُ حدودَها بنفسه — وإلّا وصل الملفُّ ولا يُقرأ.
  function uploadImage(file) {
    var fd = new FormData();
    fd.append('file', file);
    return fetch('{{ route('admin.amial.catalog.images') }}', {
      method: 'POST',
      headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF},
      body: fd
    }).then(function (r) { return r.json(); });
  }

  $('cat-new-image').addEventListener('change', function (e) {
    var box = $('cat-new-image-preview');
    var f = e.target.files[0];
    if (!f) { box.innerHTML = ''; box.dataset.path = ''; return; }
    box.innerHTML = '<span class="text-muted small">جارٍ الرفع…</span>';
    uploadImage(f).then(function (j) {
      if (!j || !j.success) {
        box.dataset.path = '';
        e.target.value = '';
        box.innerHTML = '<span class="text-danger small">' + esc((j && j.message) || 'تعذّر الرفع') + '</span>';
        return;
      }
      box.dataset.path = j.image_path;
      box.innerHTML = '<img src="' + esc(j.url) + '" alt="" style="max-height:80px;border-radius:6px">';
    });
  });

  $('cat-add-go').addEventListener('click', function () {
    post('{{ route('admin.amial.catalog.store') }}', {
      barcode: $('cat-new-barcode').value.trim(),
      name: $('cat-new-name').value.trim(),
      category: $('cat-new-category').value.trim(),
      unit: $('cat-new-unit').value.trim(),
      image_path: $('cat-new-image-preview').dataset.path || null
    }).then(function (j) {
      if (!j || !j.success) { $('cat-add-err').textContent = (j && j.message) || 'تعذّر الحفظ'; return; }
      bootstrap.Modal.getInstance($('cat-add-modal')).hide();
      loadRows(); loadStats();
    });
  });

  $('cat-import').addEventListener('click', function () {
    $('cat-import-result').innerHTML = '';
    $('cat-import-file').value = '';
    new bootstrap.Modal($('cat-import-modal')).show();
  });

  $('cat-import-go').addEventListener('click', function () {
    var f = $('cat-import-file').files[0];
    if (!f) { $('cat-import-result').innerHTML = '<span class="text-danger">اختر ملفّاً</span>'; return; }

    var fd = new FormData();
    fd.append('file', f);

    fetch('{{ route('admin.amial.catalog.import') }}', {
      method: 'POST', headers: { 'Accept':'application/json', 'X-CSRF-TOKEN': CSRF }, body: fd
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) {
        $('cat-import-result').innerHTML = '<span class="text-danger">' + esc((j && j.message) || 'فشل') + '</span>';
        return;
      }
      // **ويُقال كم رُفض ولماذا** — لا «تمّ» تبتلع ألف صفّ صامتاً.
      var m = j.meta || {}, r = m.reasons || {};
      var html = '<div class="text-success">أُضيف ' + m.added + ' · تُخطّي ' + m.skipped + '</div>';
      var keys = Object.keys(r);
      if (keys.length) {
        html += '<ul class="mt-2 mb-0">' + keys.map(function (k) {
          return '<li>' + esc(k) + ': ' + r[k] + '</li>';
        }).join('') + '</ul>';
      }
      $('cat-import-result').innerHTML = html;
      loadRows(); loadStats();
    });
  });

  document.querySelectorAll('a[data-sort]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var s = a.getAttribute('data-sort');
      dir = (sort === s && dir === 'desc') ? 'asc' : 'desc';
      sort = s; page = 1; loadRows();
    });
  });

  var t = null;
  $('cat-search').addEventListener('input', function () {
    clearTimeout(t); t = setTimeout(function () { page = 1; loadRows(); }, 300);
  });

  ['cat-status','cat-conflicts'].forEach(function (id) {
    $(id).addEventListener('change', function () { page = 1; loadRows(); });
  });

  $('cat-refresh').addEventListener('click', function () { loadRows(); loadStats(); });
  $('cat-prev').addEventListener('click', function () { if (page > 1) { page--; loadRows(); } });
  $('cat-next').addEventListener('click', function () { page++; loadRows(); });

  $('cat-export').addEventListener('click', function () {
    $('cat-export').href = '{{ route('admin.amial.catalog.export') }}?status='
      + encodeURIComponent($('cat-status').value);
  });

  loadStats();
  loadRows();
})();
</script>
@endpush
