@extends('layouts.admin.app')

{{--
    AMIAL-FUEL-VERTICAL-001 · المرحلة ٩ — مركز محطّات الوقود.

    القاعدةُ الثانيةَ عشرة: **لا يُبنى في التطبيق ما لا تراه اللوحة.**

    وبُنيت سبعُ طبقاتٍ في هذه الجولة — خزّاناتٌ ومسدساتٌ وتوريداتٌ وقياساتٌ
    ومصالحةٌ وأسعارٌ ونقدُ ورديّة. ولو بقيت بلا شاشةٍ هنا لكانت «مبنيّةً ولا
    يُوصَل إليها».

    وأوّلُ ما يُعرض ليس المبيعات: **فروقاتُ المخزون المفتوحة**. فقدُ لتراتٍ
    لا يظهر في أيّ تقريرٍ ماليّ — النقدُ مطابقٌ والوقودُ هو الذي نقص.
--}}

@section('title', 'مركز محطات الوقود')

@section('content')
<div class="content container-fluid" id="fuel-center" data-testid="fuel-center">

    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="tio-dashboard text-warning" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">مركز محطات الوقود</h2>
        <span class="badge badge-soft-secondary ms-auto">قطاع مستقل</span>
    </div>

    <div id="fc-banner"></div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#fc-var"
                    data-testid="fc-tab-variances">
                🔴 فروقات المخزون <span class="badge bg-danger" id="fc-var-count">0</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#fc-stations"
                    data-testid="fc-tab-stations">⛽ المحطات</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#fc-detail"
                    data-testid="fc-tab-detail">📋 ملف محطة</button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ── فروقات المخزون ── --}}
        <div class="tab-pane fade show active" id="fc-var">
            <div class="card">
                <div class="card-header d-flex align-items-center gap-2">
                    <h4 class="card-title mb-0">تحقيقات مفتوحة</h4>
                    <span class="ms-auto text-danger fw-bold" id="fc-loss-total">—</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>المحطة</th><th>الخزان</th><th>الفرق (لتر)</th>
                                <th>النسبة</th><th>حتى</th><th>العمر</th>
                            </tr>
                        </thead>
                        <tbody id="fc-var-body">
                            <tr><td colspan="6" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ── المحطات ── --}}
        <div class="tab-pane fade" id="fc-stations">
            <div class="card">
                <div class="card-header">
                    <input type="text" class="form-control" id="fc-search"
                           data-testid="fc-search" placeholder="ابحث باسم المحطة أو المدينة…">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>المحطة</th><th>المدينة</th><th>التاجر</th>
                                <th>خزانات</th><th>مسدسات بلا خزان</th>
                                <th>وردية</th><th>فروقات</th><th>توريدات معلقة</th><th></th>
                            </tr>
                        </thead>
                        <tbody id="fc-st-body">
                            <tr><td colspan="9" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ── ملف محطة ── --}}
        <div class="tab-pane fade" id="fc-detail">
            <div id="fc-detail-body" class="text-muted">
                اختر محطة من تبويب «المحطات».
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '—').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const BASE = '{{ url("admin/amial/fuel") }}';

    async function get(path) {
        const r = await fetch(BASE + path, { headers: { 'Accept': 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const j = await r.json();
        return j.data || {};
    }

    function banner(msg) {
        $('fc-banner').innerHTML =
            '<div class="alert alert-danger">' + esc(msg) + '</div>';
    }

    // ── فروقات المخزون ──
    async function loadVariances() {
        try {
            const d = await get('/open-variances');
            const rows = d.variances || [];
            $('fc-var-count').textContent = rows.length;
            $('fc-loss-total').textContent = rows.length
                ? ('إجمالي الفقد: ' + d.total_loss_liters + ' لتر') : 'لا فروقات مفتوحة';

            $('fc-var-body').innerHTML = rows.length ? rows.map(r =>
                '<tr>' +
                '<td>' + esc(r.station) + '</td>' +
                '<td>' + esc(r.tank) + '</td>' +
                '<td class="' + (r.is_loss ? 'text-danger fw-bold' : 'text-success') + '">' +
                    esc(r.variance_liters) + '</td>' +
                '<td>' + esc(r.variance_percent) + '%</td>' +
                '<td>' + esc(r.period_end) + '</td>' +
                '<td>' + esc(r.age_days) + ' يوم</td>' +
                '</tr>').join('')
                : '<tr><td colspan="6" class="text-center text-success py-4">' +
                  'لا فروقات مفتوحة — وهذا يعني «فُحص فلم يوجد» لا «لم يُفحص»</td></tr>';
        } catch (e) { banner('تعذّر تحميل الفروقات: ' + e.message); }
    }

    // ── المحطات ──
    async function loadStations(q) {
        try {
            const d = await get('/stations' + (q ? ('?q=' + encodeURIComponent(q)) : ''));
            const rows = d.stations || [];

            $('fc-st-body').innerHTML = rows.length ? rows.map(s =>
                '<tr>' +
                '<td>' + esc(s.name) + '</td>' +
                '<td>' + esc(s.city) + '</td>' +
                '<td>' + esc(s.merchant) + '</td>' +
                '<td>' + esc(s.tanks) + '</td>' +
                '<td>' + (s.unlinked_nozzles > 0
                    ? '<span class="badge bg-warning" title="لتراتها خارج المصالحة">' +
                      esc(s.unlinked_nozzles) + '</span>' : '0') + '</td>' +
                '<td>' + (s.open_shift
                    ? '<span class="badge bg-success">مفتوحة</span>'
                    : '<span class="badge bg-secondary">مغلقة</span>') + '</td>' +
                '<td>' + (s.open_variances > 0
                    ? '<span class="badge bg-danger">' + esc(s.open_variances) + '</span>' : '0') + '</td>' +
                '<td>' + esc(s.pending_deliveries) + '</td>' +
                '<td><button class="btn btn-sm btn-outline-primary fc-open" ' +
                    'data-id="' + s.id + '" data-testid="fc-open-' + s.id + '">الملف</button></td>' +
                '</tr>').join('')
                : '<tr><td colspan="9" class="text-center text-muted py-4">لا محطات</td></tr>';
        } catch (e) { banner('تعذّر تحميل المحطات: ' + e.message); }
    }

    // ── ملف محطة ──
    async function loadStation(id) {
        try {
            const d = await get('/stations/' + id);
            const t = d.totals || {};

            const tanks = (d.tanks || []).map(k =>
                '<tr><td>' + esc(k.name) + '</td><td>' + esc(k.product) + '</td>' +
                '<td>' + esc(k.book) + ' / ' + esc(k.capacity) + '</td>' +
                '<td>' + esc(k.fill_percent) + '%</td>' +
                '<td>' + (k.is_low ? '<span class="badge bg-warning">منخفض</span>' : '—') +
                '</td></tr>').join('')
                || '<tr><td colspan="5" class="text-muted">لا خزانات معرّفة</td></tr>';

            const recons = (d.reconciliations || []).map(r =>
                '<tr><td>' + esc(r.period_end) + '</td><td>' + esc(r.expected) + '</td>' +
                '<td>' + esc(r.actual) + '</td>' +
                '<td class="fw-bold">' + esc(r.variance) + '</td>' +
                '<td>' + esc(r.status) + '</td></tr>').join('')
                || '<tr><td colspan="5" class="text-muted">لا مصالحات — والمخزون بلا مصالحة غير مراقَب</td></tr>';

            const shifts = (d.shifts || []).map(s =>
                '<tr><td>' + esc(s.opened_at) + '</td><td>' + esc(s.expected_cash) + '</td>' +
                '<td>' + esc(s.actual_cash) + '</td>' +
                '<td class="' + (parseFloat(s.variance) < 0 ? 'text-danger' : '') + '">' +
                    esc(s.variance) + '</td>' +
                '<td>' + esc(s.status) + '</td></tr>').join('')
                || '<tr><td colspan="5" class="text-muted">لا ورديات</td></tr>';

            $('fc-detail-body').innerHTML =
                '<h4 class="mb-3">' + esc(d.station.name) + ' — ' + esc(d.station.merchant) + '</h4>' +
                '<div class="row mb-3">' +
                  card('عدد العمليات', t.sales_count) +
                  card('إجمالي اللترات', t.total_liters) +
                  card('إجمالي المبيعات', t.total_amount) +
                  card('لترات غير منسوبة', t.unattributed_liters,
                       parseFloat(t.unattributed_liters) > 0 ? 'text-warning' : '') +
                '</div>' +
                section('الخزانات', ['الخزان','الوقود','الدفتري/السعة','الامتلاء','الحالة'], tanks) +
                section('المصالحات', ['حتى','المتوقع','المقيس','الفرق','الحالة'], recons) +
                section('الورديات', ['فُتحت','متوقع','فعلي','الفرق','الحالة'], shifts);

            document.querySelector('[data-bs-target="#fc-detail"]').click();
        } catch (e) { banner('تعذّر تحميل الملف: ' + e.message); }
    }

    function card(label, value, cls) {
        return '<div class="col-md-3"><div class="card"><div class="card-body">' +
            '<div class="text-muted small">' + esc(label) + '</div>' +
            '<div class="h4 mb-0 ' + (cls || '') + '">' + esc(value) + '</div>' +
            '</div></div></div>';
    }

    function section(title, heads, body) {
        return '<div class="card mb-3"><div class="card-header"><h5 class="mb-0">' +
            esc(title) + '</h5></div><div class="table-responsive"><table class="table mb-0">' +
            '<thead class="thead-light"><tr>' +
            heads.map(h => '<th>' + esc(h) + '</th>').join('') +
            '</tr></thead><tbody>' + body + '</tbody></table></div></div>';
    }

    // ── الأحداث ──
    // **الاستماع على الجدول لا على الزرّ**: الصفوف تُعاد بناؤها مع كلّ بحث،
    // ومستمعٌ على زرٍّ أُزيل يموت صامتاً. (القاعدة التاسعة)
    document.getElementById('fc-st-body').addEventListener('click', function (e) {
        const btn = e.target.closest('.fc-open');
        if (btn) { loadStation(btn.dataset.id); }
    });

    let timer = null;
    $('fc-search').addEventListener('input', function () {
        clearTimeout(timer);
        const v = this.value;
        timer = setTimeout(() => loadStations(v), 300);
    });

    loadVariances();
    loadStations('');
})();
</script>
@endsection
