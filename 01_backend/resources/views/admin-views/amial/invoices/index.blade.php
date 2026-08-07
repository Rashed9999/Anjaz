@extends('layouts.admin.app')

@section('title', 'فواتير التجّار')

@section('content')
{{--
    AMIAL-MERCHANT-PAY-002 — مركز فواتير التجّار.

    الشرط الذي وُلدت منه هذه الشاشة: «كل شيء له مصدر وجذر قابل للاستعلام
    والمراجعة والتعديل». والفاتورة تُنشأ من تطبيق التاجر وتُدفع من تطبيق
    العميل — وكانت لا تُرى من الإدارة إلا في جدول عام بلا بحث ولا تصدير.

    والمصدر واحد: `payment_requests` نفسه. لا جدول ثانٍ «للإدارة».
--}}

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">لوحة التحكم</a></li>
    <li class="breadcrumb-item active">فواتير التجّار</li>
  </ol>
</nav>

{{-- ═══ المؤشّرات ═══ --}}
<div class="row g-3 mb-4" id="inv-kpis">
  @foreach ([
    ['issued_today',       'فواتير اليوم',        'primary'],
    ['paid_today',         'مدفوعة اليوم',        'success'],
    ['paid_today_amount',  'محصَّل اليوم (ر.ي)',  'success'],
    ['pending_open',       'معلّقة الآن',         'warning'],
    ['collection_rate',    'نسبة التحصيل',        'info'],
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

{{-- ═══ الرسم: أربعةَ عشرَ يوماً ═══ --}}
<div class="card mb-4">
  <div class="card-body">
    <h6 class="mb-3">الفواتير والمحصَّل — ١٤ يوماً</h6>
    <div id="inv-chart" class="d-flex align-items-end gap-1" style="height:130px;">
      <div class="text-muted small">…</div>
    </div>
  </div>
</div>

{{-- ═══ الأدوات ═══ --}}
<div class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-end">
    <div>
      <label class="form-label small mb-1">بحث</label>
      <input id="inv-search" class="form-control form-control-sm"
             placeholder="رقم فاتورة · تاجر · هاتف · مبلغ" style="min-width:230px">
    </div>
    <div>
      <label class="form-label small mb-1">الحالة</label>
      <select id="inv-status" class="form-select form-select-sm">
        <option value="">الكل</option>
        <option value="pending">معلّقة</option>
        <option value="paid">مدفوعة</option>
        <option value="cancelled">ملغاة</option>
        <option value="expired">منتهية</option>
      </select>
    </div>
    <div>
      <label class="form-label small mb-1">من</label>
      <input id="inv-from" type="date" class="form-control form-control-sm">
    </div>
    <div>
      <label class="form-label small mb-1">إلى</label>
      <input id="inv-to" type="date" class="form-control form-control-sm">
    </div>
    <button id="inv-refresh" class="btn btn-sm btn-outline-secondary">تحديث</button>
    <a id="inv-export" class="btn btn-sm btn-outline-success"
       href="{{ route('admin.amial.invoices.export') }}">تصدير CSV</a>
  </div>
</div>

{{-- ═══ الجدول ═══ --}}
<div class="card">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th><a href="#" data-sort="created_at">التاريخ</a></th>
          <th>رقم الفاتورة</th>
          <th>التاجر</th>
          <th><a href="#" data-sort="amount">المبلغ</a></th>
          <th><a href="#" data-sort="status">الحالة</a></th>
          <th>رقم الحركة</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="inv-rows">
        <tr><td colspan="7" class="text-muted p-4">…</td></tr>
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="small text-muted" id="inv-count">—</span>
    <div class="btn-group btn-group-sm">
      <button id="inv-prev" class="btn btn-outline-secondary">السابق</button>
      <button id="inv-next" class="btn btn-outline-secondary">التالي</button>
    </div>
  </div>
</div>

{{-- ═══ نافذة التفصيل ═══ --}}
<div class="modal fade" id="inv-detail" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">تفصيل الفاتورة</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="inv-detail-body">…</div>
    </div>
  </div>
</div>

{{-- ═══ نافذة الإلغاء ═══ --}}
<div class="modal fade" id="inv-cancel" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">إلغاء فاتورة</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">
          لا تُلغى إلّا الفاتورة غير المدفوعة. والمدفوعة سجلٌّ ماليّ تاريخيّ
          يُصحَّح بردٍّ يترك أثره، لا بمحوٍ يُخفيه.
        </p>
        <div class="mb-2"><strong id="inv-cancel-label">—</strong></div>
        <label class="form-label small">سبب الإلغاء (إلزاميّ)</label>
        <input id="inv-cancel-reason" class="form-control form-control-sm"
               placeholder="من يراجع السجلّ بعد شهر يحتاج التفسير">
        <div class="text-danger small mt-2" id="inv-cancel-err"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">تراجع</button>
        <button class="btn btn-sm btn-danger" id="inv-cancel-go">تأكيد الإلغاء</button>
      </div>
    </div>
  </div>
</div>
@endsection

@push('script')
<script>
(function () {
  'use strict';

  var page = 1, sort = 'created_at', dir = 'desc', cancelId = null;

  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  };

  var BADGE = { paid:'success', pending:'warning', cancelled:'secondary', expired:'dark' };
  var LABEL = { paid:'مدفوعة', pending:'معلّقة', cancelled:'ملغاة', expired:'منتهية' };

  function get(url) {
    return fetch(url, { headers: { 'Accept':'application/json' } }).then(function (r) { return r.json(); });
  }

  // ═══ المؤشّرات ═══
  function loadStats() {
    get('{{ route('admin.amial.invoices.stats') }}').then(function (j) {
      var m = (j && j.meta) || {};

      document.querySelectorAll('[data-kpi]').forEach(function (el) {
        var k = el.getAttribute('data-kpi'), v = m[k];

        // «لا فواتير اليوم» ليست «٠٪». الغياب يُقال ولا يُقرأ صفراً.
        if (k === 'collection_rate') {
          el.textContent = (v === null || v === undefined) ? 'لا فواتير اليوم' : v + '٪';
          return;
        }
        el.textContent = (v === null || v === undefined) ? '—' : v;
      });

      drawChart(m.trend || []);
    });
  }

  function drawChart(t) {
    var box = $('inv-chart');
    if (!t.length) { box.innerHTML = '<div class="text-muted small">لا بيانات</div>'; return; }

    var max = 1;
    t.forEach(function (d) { if (d.issued > max) max = d.issued; });

    box.innerHTML = t.map(function (d) {
      var h = Math.round((d.issued / max) * 100);
      var hp = Math.round((d.paid / max) * 100);
      return '<div class="flex-fill d-flex flex-column justify-content-end" style="height:100%" '
        + 'title="' + esc(d.day) + ': أُصدرت ' + d.issued + ' · دُفعت ' + d.paid + '">'
        + '<div style="background:#dbe7f5;height:' + h + '%;position:relative;border-radius:3px 3px 0 0">'
        + '<div style="background:#198754;height:' + (h ? Math.round((hp / h) * 100) : 0) + '%;'
        + 'position:absolute;bottom:0;left:0;right:0;border-radius:3px 3px 0 0"></div></div></div>';
    }).join('');
  }

  // ═══ الجدول ═══
  function query() {
    return '?page=' + page + '&sort=' + sort + '&dir=' + dir
      + '&search=' + encodeURIComponent($('inv-search').value.trim())
      + '&status=' + encodeURIComponent($('inv-status').value)
      + '&from=' + encodeURIComponent($('inv-from').value)
      + '&to=' + encodeURIComponent($('inv-to').value);
  }

  function loadRows() {
    get('{{ route('admin.amial.invoices.rows') }}' + query()).then(function (j) {
      var m = (j && j.meta) || {}, rows = m.rows || [];
      var body = $('inv-rows');

      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" class="text-muted p-4">لا فواتير مطابقة</td></tr>';
      } else {
        body.innerHTML = rows.map(function (r) {
          return '<tr>'
            + '<td class="small text-muted">' + esc(r.created_at) + '</td>'
            + '<td><code>' + esc(r.invoice_no) + '</code></td>'
            + '<td>' + esc(r.merchant) + '<div class="small text-muted">' + esc(r.merchant_phone) + '</div></td>'
            + '<td>' + esc(r.amount) + '</td>'
            + '<td><span class="badge bg-' + (BADGE[r.status] || 'secondary') + '">'
            + esc(LABEL[r.status] || r.status) + '</span></td>'
            + '<td class="small">' + (r.transaction_id ? '<code>' + esc(r.transaction_id) + '</code>'
                : '<span class="text-muted">—</span>') + '</td>'
            + '<td class="text-nowrap">'
            + '<button class="btn btn-sm btn-outline-primary" data-act="show" data-id="' + r.id + '">تفصيل</button> '
            + (r.can_cancel
                ? '<button class="btn btn-sm btn-outline-danger" data-act="cancel" data-id="' + r.id
                  + '" data-label="' + esc(r.invoice_no + ' — ' + r.amount + ' ر.ي') + '">إلغاء</button>'
                : '<span class="small text-muted">لا يُلغى</span>')
            + '</td></tr>';
        }).join('');
      }

      $('inv-count').textContent = 'الإجمالي ' + (m.total || 0) + ' · صفحة ' + (m.page || 1) + ' من ' + (m.last_page || 1);
      $('inv-prev').disabled = (m.page || 1) <= 1;
      $('inv-next').disabled = (m.page || 1) >= (m.last_page || 1);
    });
  }

  // ═══ التفصيل ═══
  function showDetail(id) {
    var body = $('inv-detail-body');
    body.innerHTML = '<div class="text-muted">…</div>';
    new bootstrap.Modal($('inv-detail')).show();

    get('{{ url('admin/amial/invoices') }}/' + id).then(function (j) {
      if (!j || !j.success) { body.innerHTML = '<div class="text-danger">تعذّر الجلب</div>'; return; }

      var v = j.meta.invoice, p = j.meta.payer, t = j.meta.transaction, miss = j.meta.transaction_missing;

      var rows = [
        ['رقم الفاتورة', v.invoice_no],
        ['التاجر', v.merchant + ' — ' + v.merchant_phone],
        ['المبلغ', v.amount + ' ر.ي'],
        ['الحالة', LABEL[v.status] || v.status],
        ['الملاحظة', v.note || '—'],
        ['أُنشئت', v.created_at],
        ['تنتهي', v.expires_at || '—'],
        ['دُفعت', v.paid_at || '—'],
        ['الدافع', p ? (p.name + ' — ' + p.phone) : '—'],
      ];

      var html = '<table class="table table-sm">' + rows.map(function (r) {
        return '<tr><th class="text-muted small" style="width:32%">' + esc(r[0]) + '</th><td>' + esc(r[1]) + '</td></tr>';
      }).join('') + '</table>';

      // الأثر في المال — ويُقال صراحةً إن غاب.
      if (miss) {
        html += '<div class="alert alert-danger small mb-0">⚠ ' + esc(miss) + '</div>';
      } else if (t) {
        html += '<div class="alert alert-light border small mb-0">'
          + '<div><strong>الحركة المالية</strong></div>'
          + '<div>رقم الحركة: <code>' + esc(t.transaction_id) + '</code></div>'
          + '<div>المبلغ: ' + esc(t.amount) + ' · الرسم: ' + esc(t.charge) + '</div>'
          + '<div class="text-muted">' + esc(t.created_at) + '</div></div>';
      } else {
        html += '<div class="text-muted small">لم تُدفع بعد — لا حركة مالية.</div>';
      }

      body.innerHTML = html;
    });
  }

  // ═══ الإلغاء ═══
  function askCancel(id, label) {
    cancelId = id;
    $('inv-cancel-label').textContent = label;
    $('inv-cancel-reason').value = '';
    $('inv-cancel-err').textContent = '';
    new bootstrap.Modal($('inv-cancel')).show();
  }

  $('inv-cancel-go').addEventListener('click', function () {
    var reason = $('inv-cancel-reason').value.trim();
    if (!reason) { $('inv-cancel-err').textContent = 'اكتب سبب الإلغاء'; return; }

    fetch('{{ url('admin/amial/invoices') }}/' + cancelId + '/cancel', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({ reason: reason })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) { $('inv-cancel-err').textContent = (j && j.message) || 'تعذّر الإلغاء'; return; }
      bootstrap.Modal.getInstance($('inv-cancel')).hide();
      loadRows(); loadStats();
    });
  });

  // ═══ الأزرار: تفويضٌ على الجسم لا على كل صفّ ═══
  // والصفّ لا يحمل data-act حتى لا تبتلع `closest` ضغطة الزرّ فتفتح
  // شيئاً غير الذي ضُغط.
  $('inv-rows').addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    e.preventDefault();
    if (btn.getAttribute('data-act') === 'show') showDetail(btn.getAttribute('data-id'));
    else askCancel(btn.getAttribute('data-id'), btn.getAttribute('data-label'));
  });

  document.querySelectorAll('a[data-sort]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var s = a.getAttribute('data-sort');
      dir = (sort === s && dir === 'desc') ? 'asc' : 'desc';
      sort = s; page = 1; loadRows();
    });
  });

  var timer = null;
  $('inv-search').addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () { page = 1; loadRows(); }, 300);
  });

  ['inv-status', 'inv-from', 'inv-to'].forEach(function (id) {
    $(id).addEventListener('change', function () { page = 1; loadRows(); });
  });

  $('inv-refresh').addEventListener('click', function () { loadRows(); loadStats(); });
  $('inv-prev').addEventListener('click', function () { if (page > 1) { page--; loadRows(); } });
  $('inv-next').addEventListener('click', function () { page++; loadRows(); });

  // التصدير يحمل الفلتر الحاليّ — لا يُصدّر شيئاً غير المعروض.
  $('inv-export').addEventListener('click', function () {
    $('inv-export').href = '{{ route('admin.amial.invoices.export') }}?status='
      + encodeURIComponent($('inv-status').value);
  });

  loadStats();
  loadRows();
})();
</script>
@endpush
