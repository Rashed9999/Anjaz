@extends('layouts.admin.app')

@section('title', 'حدود بوت واتساب')

@section('content')
{{--
    AMIAL-WA-LIMIT-001 — سقف المال عبر بوت واتساب.

    ولمَ شاشة لا متغيّر بيئة: وقع هذا قبلاً — أرقام العرض للـOTP كانت
    تُضبط بمتغيّر بيئة وحده، فكان تغييرها يعني نشراً كاملاً، فلا تُغيَّر.
    والسقف الذي لا يُغيَّر ليس سقفاً يُدار: هو رقم يُنسى.
--}}

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">لوحة التحكم</a></li>
    <li class="breadcrumb-item active">حدود بوت واتساب</li>
  </ol>
</nav>

<div class="alert alert-light border small" id="wa-why">
  <strong>لماذا للبوت سقفٌ خاصّ؟</strong>
  البوت يتحقّق من رمز الحماية كما يتحقّق التطبيق — لكنّ الرمز في واتساب
  <strong>يُكتب في المحادثة</strong>، فيبقى في سجلّها على الهاتفين وعند المزوّد.
  فالسقف هنا ليس حاجزاً أقوى، بل <strong>تحديدٌ لأكبر خسارة ممكنة</strong>
  حين يُقرأ الرمز من محادثة.
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <h6 class="mb-3">الحدّ الأعلى للعمليّة الواحدة</h6>

        <div id="wa-state" class="mb-3"></div>

        <div class="mb-3">
          <label class="form-label small">الحدّ الأعلى (ريال يمنيّ)</label>
          <input id="wa-max" type="number" min="0" step="1" class="form-control">
          <div class="form-text">
            <strong>صفر = إيقاف كلّ العمليّات المالية عبر واتساب</strong> — ولا يُقفل الاستعلام ولا الربط.
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small">سبب التغيير (إلزاميّ)</label>
          <input id="wa-reason" class="form-control" placeholder="من يراجع السجلّ بعد شهر يحتاج التفسير">
        </div>

        <div class="text-danger small mb-2" id="wa-err"></div>
        <div class="text-success small mb-2" id="wa-ok"></div>

        <button id="wa-save" class="btn btn-primary">حفظ</button>
        <button id="wa-refresh" class="btn btn-outline-secondary">تحديث</button>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <h6 class="mb-3">أين يُطبَّق</h6>
        <ul class="small mb-3" id="wa-paths"><li class="text-muted">…</li></ul>

        <div class="border-top pt-3">
          <div class="text-muted small">عمليّات منعها السقف اليوم</div>
          <div class="h4 mb-0" id="wa-blocked">…</div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

  function load() {
    fetch('{{ route('admin.amial.whatsapp.limits.show') }}', { headers: { 'Accept':'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var m = (j && j.meta) || {};
        $('wa-max').value = m.max_amount || '';

        // **وتُقال حالةُ القيمة.** رقمٌ معروضٌ بلا بيان مصدره يُقرأ
        // «مضبوط» — ثمّ يُكتشف أنّه افتراضيّ لم يمسّه أحد.
        $('wa-state').innerHTML = m.is_configured
          ? '<span class="badge bg-success">مضبوطة من الإدارة</span>'
          : '<span class="badge bg-warning text-dark">قيمة افتراضيّة — لم تُضبط بعد</span>'
            + '<div class="small text-muted mt-1">الافتراضيّ ' + (m.default || '—') + ' ر.ي. احفظ لتثبيتها.</div>';

        $('wa-paths').innerHTML = (m.paths || []).map(function (p) {
          return '<li>' + p + '</li>';
        }).join('') || '<li class="text-muted">—</li>';

        $('wa-blocked').textContent = (m.blocked_today === null || m.blocked_today === undefined)
          ? '—' : m.blocked_today;
      });
  }

  $('wa-save').addEventListener('click', function () {
    $('wa-err').textContent = '';
    $('wa-ok').textContent = '';

    fetch('{{ route('admin.amial.whatsapp.limits.save') }}', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({
        max_amount: $('wa-max').value,
        reason: $('wa-reason').value
      })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) { $('wa-err').textContent = (j && j.message) || 'تعذّر الحفظ'; return; }
      $('wa-ok').textContent = j.message || 'حُفظ';
      $('wa-reason').value = '';
      load();
    });
  });

  $('wa-refresh').addEventListener('click', load);

  load();
})();
</script>
@endpush
