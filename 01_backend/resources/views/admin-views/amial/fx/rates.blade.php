@extends('layouts.admin.app')

@section('title', 'أسعار الصرف')

@section('content')
{{--
    AMIAL-MULTI-CURRENCY-002 — أسعار الصرف.

    ولمَ شاشة: بلا هذه الصفحة تبقى محافظ العملات مبنيّةً ولا يُوصَل إليها —
    لا سعرَ يُضبَط، فلا قبضَ ولا صرف. وسعرُ الصرف في اليمن يتحرّك في اليوم
    الواحد، فرقمٌ لا يُغيَّر إلّا بنشرةٍ يشيخ ويكذب.
--}}

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">لوحة التحكم</a></li>
    <li class="breadcrumb-item active">أسعار الصرف</li>
  </ol>
</nav>

<div class="alert alert-warning border small">
  <strong>هذا الرقم يمسّ مالاً قائماً.</strong>
  كلُّ محفظةٍ بعملةٍ أجنبيّة تُقاس قيمتُها بهذا السعر، وكلُّ صرفٍ بعد
  حفظه يقع به. والأسعارُ <strong>تُضاف ولا تُعدَّل</strong>: السعرُ القديم
  يبقى ليُقرأ به ما وقع في زمنه — فمكافئُ فاتورةِ الأمس لا يتغيّر اليوم.
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <h6 class="mb-3">الأسعار السارية الآن</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr>
              <th>العملة</th><th>1 وحدة =</th><th>المصدر</th><th>سريانه</th>
            </tr></thead>
            <tbody id="fx-rows"><tr><td colspan="4" class="text-muted">…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body">
        <h6 class="mb-3">ضبط سعر</h6>

        <div class="mb-3">
          <label class="form-label small">العملة</label>
          <select id="fx-cur" class="form-select"></select>
        </div>

        <div class="mb-3">
          <label class="form-label small">1 وحدة = كم <span id="fx-base-sym">ر.ي</span></label>
          <input id="fx-rate" type="number" min="0" step="0.00000001" class="form-control">
          <div class="form-text">مثال: الدولار 530 يعني أنّ دولاراً واحداً = 530 ريالاً يمنيّاً.</div>
        </div>

        <div class="mb-3">
          <label class="form-label small">السبب أو المصدر (يُحفظ في سجلّ التدقيق)</label>
          <input id="fx-note" type="text" maxlength="160" class="form-control"
                 placeholder="مثال: سعر السوق الموازي — صنعاء">
        </div>

        <button id="fx-save" class="btn btn-primary btn-sm">حفظ السعر</button>
        <button id="fx-refresh" class="btn btn-outline-secondary btn-sm">تحديث</button>

        <div id="fx-err" class="text-danger small mt-2"></div>
        <div id="fx-ok" class="text-success small mt-2"></div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <h6 class="mb-3">آخر التغييرات</h6>
        <div class="table-responsive" style="max-height:520px;overflow:auto">
          <table class="table table-sm small align-middle">
            <thead><tr><th>العملة</th><th>السعر</th><th>من</th><th>متى</th></tr></thead>
            <tbody id="fx-history"><tr><td colspan="4" class="text-muted">…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('script')
{{-- بلا `nonce` يمنعه المتصفّحُ صامتاً، فتردّ الصفحةُ ٢٠٠ وهي ميّتة. --}}
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function load() {
    fetch('{{ route('admin.amial.fx.rates.show') }}', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var m = (j && j.meta) || {};
        var rates = m.rates || [];

        $('fx-base-sym').textContent = m.base_symbol || 'ر.ي';

        // **وعملةٌ بلا سعرٍ تُعرَض وتُوسَم** — حذفُها من الجدول يُقرأ
        // «كلُّ شيءٍ مضبوط» وهو «لم يُنظَر».
        $('fx-rows').innerHTML = rates.map(function (r) {
          var val = r.is_base
            ? '<span class="text-muted">1 (الأساس)</span>'
            : (r.missing
                ? '<span class="badge bg-warning text-dark">لم يُضبط بعد</span>'
                : esc(r.rate) + ' ' + esc(m.base_symbol || ''));
          return '<tr>'
            + '<td><strong>' + esc(r.code) + '</strong> <span class="text-muted small">'
              + esc(r.name) + '</span></td>'
            + '<td>' + val + '</td>'
            + '<td class="small">' + esc(r.source || '—') + '</td>'
            + '<td class="small">' + esc((r.effective_at || '—').split('T')[0]) + '</td>'
            + '</tr>';
        }).join('') || '<tr><td colspan="4" class="text-muted">—</td></tr>';

        // الأساسُ لا يُضبَط — سعرُه ١ بالتعريف، فلا يُعرَض في القائمة.
        $('fx-cur').innerHTML = rates.filter(function (r) { return !r.is_base; })
          .map(function (r) {
            return '<option value="' + esc(r.code) + '">'
              + esc(r.name) + ' (' + esc(r.code) + ')</option>';
          }).join('');

        $('fx-history').innerHTML = (m.history || []).map(function (h) {
          return '<tr>'
            + '<td>' + esc(h.currency) + '</td>'
            + '<td>' + esc(h.rate) + '</td>'
            + '<td>' + esc(h.by) + '</td>'
            + '<td>' + esc(h.effective_at || '—') + '</td>'
            + '</tr>';
        }).join('') || '<tr><td colspan="4" class="text-muted">لا تغييرات بعد</td></tr>';
      });
  }

  $('fx-save').addEventListener('click', function () {
    $('fx-err').textContent = '';
    $('fx-ok').textContent = '';

    fetch('{{ route('admin.amial.fx.rates.save') }}', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({
        currency: $('fx-cur').value,
        rate_to_base: $('fx-rate').value,
        note: $('fx-note').value
      })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) { $('fx-err').textContent = (j && j.message) || 'تعذّر الحفظ'; return; }
      $('fx-ok').textContent = (j.message || 'حُفظ')
        + (j.meta && j.meta.before ? ' (كان ' + j.meta.before + ')' : '');
      $('fx-rate').value = '';
      $('fx-note').value = '';
      load();
    });
  });

  $('fx-refresh').addEventListener('click', load);

  load();
})();
</script>
@endpush
