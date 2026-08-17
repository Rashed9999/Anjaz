@extends('layouts.admin.app')

{{--
    AMIAL-RETAIL-VERTICAL-001 · المرحلة ١١ — مركز التجزئة.

    القاعدةُ الثانيةَ عشرة: **لا يُبنى في التطبيق ما لا تراه اللوحة.**

    وأوّلُ ما يُعرض ليس المبيعات: **المخزونُ السالب**. فقبل المرحلة ٠ كان
    بيعُ خمسٍ وفي النظام ثلاثٌ يُقصّ إلى صفرٍ صامتاً، فيُقفَل الفرقُ قبل
    أن يُرى. وصار السالبُ يبقى ظاهراً — وهذه الشاشةُ هي التي تراه.
--}}

@section('title', 'مركز التجزئة')

@section('content')
<div class="content container-fluid" id="retail-center" data-testid="retail-center">

    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="tio-shopping-cart text-primary" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">مركز التجزئة</h2>
        <span class="badge badge-soft-secondary ms-auto">رقابة لا إدارة</span>
    </div>

    <div id="rc-banner"></div>

    {{-- ── مؤشّرات المنصّة ── --}}
    <div class="row mb-3" id="rc-kpis"></div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#rc-neg"
                    data-testid="rc-tab-negative">
                🔴 مخزون سالب <span class="badge bg-danger" id="rc-neg-count">0</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rc-stuck"
                    data-testid="rc-tab-stuck">🚚 عالق في الطريق</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rc-merchants"
                    data-testid="rc-tab-merchants">🏪 المتاجر</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rc-detail"
                    data-testid="rc-tab-detail">📋 ملف متجر</button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ── المخزون السالب ── --}}
        <div class="tab-pane fade show active" id="rc-neg">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">صفوف مخزون تحت الصفر</h4>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr><th>الصنف</th><th>الموقع</th><th>الرصيد</th><th>آخر جرد</th></tr>
                        </thead>
                        <tbody id="rc-neg-body">
                            <tr><td colspan="4" class="text-center text-muted p-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">
                    الرصيد السالب ليس عطلاً في النظام — هو أثرُ بيعٍ تجاوز المسجَّل.
                    يُصلَح بجرد، ووجودُه شهوراً يعني أنّ أحداً لا يجرد.
                </div>
            </div>
        </div>

        {{-- ── عالق في الطريق ── --}}
        <div class="tab-pane fade" id="rc-stuck">
            <div class="card">
                <div class="card-header d-flex align-items-center gap-2">
                    <h4 class="card-title mb-0">تحويلات طال طريقُها</h4>
                    <select class="form-select form-select-sm ms-auto" style="width:auto"
                            id="rc-stuck-days" data-testid="rc-stuck-days">
                        <option value="1">أكثر من يوم</option>
                        <option value="3" selected>أكثر من ٣ أيام</option>
                        <option value="7">أكثر من أسبوع</option>
                        <option value="30">أكثر من شهر</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr><th>المرجع</th><th>من</th><th>إلى</th><th>الكمية</th>
                                <th>أُرسل</th><th>العمر</th></tr>
                        </thead>
                        <tbody id="rc-stuck-body">
                            <tr><td colspan="6" class="text-center text-muted p-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">
                    بضاعةٌ خرجت من موقعٍ ولم تصل الآخر. أسبوعٌ في الطريق ضياعٌ لم يُعلَن بعد.
                </div>
            </div>
        </div>

        {{-- ── المتاجر ── --}}
        <div class="tab-pane fade" id="rc-merchants">
            <div class="card">
                <div class="card-header d-flex align-items-center gap-2">
                    <h4 class="card-title mb-0">المتاجر</h4>
                    <input type="search" class="form-control form-control-sm ms-auto"
                           style="width:220px" id="rc-search" data-testid="rc-search"
                           placeholder="بحث بالاسم أو الهاتف">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr><th>المتجر</th><th>المواقع</th><th>الأصناف</th>
                                <th>سالب</th><th>لم يُجرَد</th><th>في الطريق</th>
                                <th>ينتظر</th><th></th></tr>
                        </thead>
                        <tbody id="rc-merchants-body">
                            <tr><td colspan="8" class="text-center text-muted p-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ── ملف متجر ── --}}
        <div class="tab-pane fade" id="rc-detail">
            <div id="rc-detail-body">
                <div class="card"><div class="card-body text-center text-muted p-5">
                    اختر متجراً من تبويب «المتاجر» لعرض ملفّه
                </div></div>
            </div>
        </div>

    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    // AMIAL-SWEEP-001 — **التعليقُ الذي يشرح العطلَ كان هو العطل.**
    //
    // كُتب هنا شرحٌ لتجنّب توجيهٍ من توجيهات Blade، **وذُكر التوجيهُ في
    // الشرح بلا تهريب** — فصرّفه Blade داخل تعليق الجافاسكربت إلى دالّةٍ
    // بلا معاملٍ أوّل، فسقط الملفُّ كلُّه بخطأ تركيب.
    //
    // **والصفحةُ تردّ ٥٠٠ منذ كُتبت**، ولم يكشفها شيء: طبقةُ «الصفحات لا
    // تنهار» في `verify.sh` تفتح خمسَ عشرةَ صفحةً مختارةً بيد، وهذه ليست
    // فيها. فبُني `scripts/sweep-admin.php` ليفتحها كلَّها — وكشفها أوّلَ
    // تشغيل. (وهي أختُ القاعدة الثانية: تعليقٌ عربيٌّ أخفى عطلاً من قبل.)
    //
    // ويُهرَّب بالمضاعفة: @@json.
    //
    // **ويُستعمل `url()`**: مدقّقُ الجافاسكربت في `verify.sh` يقرأ الكتلةَ
    // خاماً، وتوجيهُ Blade ليس جافاسكربت صالحةً قبل التصيير.
    const B = '{{ url("admin/amial/retail") }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g,
        m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    const fmt = n => Number(n ?? 0).toLocaleString('en-US',
        {minimumFractionDigits: 0, maximumFractionDigits: 2});

    function banner(msg, kind) {
        document.getElementById('rc-banner').innerHTML =
            '<div class="alert alert-' + kind + '">' + esc(msg) + '</div>';
    }

    async function get(path, params) {
        const url = new URL(B.replace(/\/$/, '') + path, window.location.origin);
        Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
        const r = await fetch(url, {headers: {'Accept': 'application/json'}});
        if (!r.ok) {
            // **الفشلُ يُقال** — وجدولٌ فارغٌ بلا سبب يُقرأ «لا بيانات».
            throw new Error('تعذّر الجلب (' + r.status + ')');
        }
        return (await r.json()).data;
    }

    // ── مؤشّرات المنصّة ──
    async function loadOverview() {
        try {
            const d = await get('/overview');
            const cards = [
                ['متاجر بمخزون', d.merchants_with_stock, 'primary'],
                ['مواقع', d.locations + ' (' + d.warehouses + ' مستودع)', 'secondary'],
                ['صفوف سالبة', d.negative_rows, d.negative_rows > 0 ? 'danger' : 'success'],
                ['لم يُجرَد قطّ', d.never_counted_rows, 'warning'],
                ['في الطريق', d.in_transit, 'info'],
                ['حجوزات منتهية', d.expired_holds,
                    d.expired_holds > 0 ? 'danger' : 'success'],
            ];
            document.getElementById('rc-kpis').innerHTML = cards.map(c =>
                '<div class="col-6 col-lg-2 mb-2"><div class="card h-100"><div class="card-body p-3">'
                + '<div class="small text-muted">' + esc(c[0]) + '</div>'
                + '<div class="fs-4 fw-bold text-' + c[2] + '">' + esc(c[1]) + '</div>'
                + '</div></div></div>').join('');
        } catch (e) { banner(e.message, 'danger'); }
    }

    // ── المخزون السالب ──
    async function loadNegative() {
        const body = document.getElementById('rc-neg-body');
        try {
            const d = await get('/negative-stock');
            document.getElementById('rc-neg-count').textContent = d.count;
            body.innerHTML = d.rows.length ? d.rows.map(r =>
                '<tr><td>' + esc(r.product) + '</td><td>' + esc(r.location) + '</td>'
                + '<td class="text-danger fw-bold">' + esc(r.on_hand) + '</td>'
                // **«لم يُجرَد» ليست تاريخاً فارغاً** — تُقال بنصّها.
                + '<td>' + (r.last_counted_at ? esc(r.last_counted_at)
                    : '<span class="text-warning">لم يُجرَد قطّ</span>') + '</td></tr>'
            ).join('')
            : '<tr><td colspan="4" class="text-center text-success p-4">'
              + 'لا صفَّ تحت الصفر — وهذا يُقرأ «فُحص فلم يوجد» لا «لم يُفحص»</td></tr>';
        } catch (e) {
            body.innerHTML = '<tr><td colspan="4" class="text-center text-danger p-4">'
                + esc(e.message) + '</td></tr>';
        }
    }

    // ── عالق في الطريق ──
    async function loadStuck() {
        const body = document.getElementById('rc-stuck-body');
        const days = document.getElementById('rc-stuck-days').value;
        try {
            const d = await get('/stuck-transfers', {days: days});
            body.innerHTML = d.rows.length ? d.rows.map(r =>
                '<tr><td class="font-monospace small">' + esc(r.code) + '</td>'
                + '<td>' + esc(r.from) + '</td><td>' + esc(r.to) + '</td>'
                + '<td>' + esc(r.quantity) + '</td>'
                + '<td class="small">' + esc(r.shipped_at) + '</td>'
                + '<td class="text-danger fw-bold">' + esc(r.days) + ' يوماً</td></tr>'
            ).join('')
            : '<tr><td colspan="6" class="text-center text-success p-4">'
              + 'لا تحويل تجاوز المهلة</td></tr>';
        } catch (e) {
            body.innerHTML = '<tr><td colspan="6" class="text-center text-danger p-4">'
                + esc(e.message) + '</td></tr>';
        }
    }

    // ── المتاجر ──
    async function loadMerchants() {
        const body = document.getElementById('rc-merchants-body');
        const q = document.getElementById('rc-search').value.trim();
        try {
            // AMIAL-MERCHANT-360-DRILL-001 — الفتحُ على تاجرٍ بعينه.
            const mid = new URLSearchParams(location.search).get('merchant');
            const params = q ? {q: q} : {};
            if (mid) params.merchant_id = mid;
            const d = await get('/merchants', params);
            body.innerHTML = d.merchants.length ? d.merchants.map(m => {
                const waiting = (m.pending_wastes || 0) + (m.counts_in_review || 0);
                return '<tr><td><strong>' + esc(m.name) + '</strong>'
                    + '<div class="small text-muted">' + esc(m.phone) + '</div></td>'
                    + '<td>' + esc(m.locations) + '</td>'
                    + '<td>' + esc(m.products) + '</td>'
                    + '<td class="' + (m.negative_stock_rows > 0 ? 'text-danger fw-bold' : '')
                        + '">' + esc(m.negative_stock_rows) + '</td>'
                    + '<td class="' + (m.never_counted_rows > 0 ? 'text-warning' : '')
                        + '">' + esc(m.never_counted_rows) + '</td>'
                    + '<td>' + esc(m.in_transit) + '</td>'
                    + '<td>' + esc(waiting) + '</td>'
                    + '<td><button class="btn btn-sm btn-outline-primary" data-mid="'
                        + esc(m.id) + '" data-testid="rc-open-merchant">الملف</button></td></tr>';
            }).join('')
            : '<tr><td colspan="8" class="text-center text-muted p-4">'
              + 'لا متاجر بمخزونٍ مُفعَّل بعد</td></tr>';
        } catch (e) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-danger p-4">'
                + esc(e.message) + '</td></tr>';
        }
    }

    // ── ملف متجر ──
    async function loadMerchant(id) {
        const box = document.getElementById('rc-detail-body');
        box.innerHTML = '<div class="card"><div class="card-body text-center p-5">جارٍ التحميل…</div></div>';
        try {
            const d = await get('/merchants/' + id);

            const card = (title, inner) =>
                '<div class="card mb-3"><div class="card-header"><h4 class="card-title mb-0">'
                + esc(title) + '</h4></div><div class="card-body p-0">' + inner + '</div></div>';

            const table = (heads, rows) =>
                '<div class="table-responsive"><table class="table table-sm mb-0"><thead class="thead-light"><tr>'
                + heads.map(h => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>'
                + (rows.length ? rows.join('')
                    : '<tr><td colspan="' + heads.length
                      + '" class="text-center text-muted p-3">لا سجلات</td></tr>')
                + '</tbody></table></div>';

            box.innerHTML =
                '<h4 class="mb-3">' + esc(d.merchant.name) + ' · ' + esc(d.merchant.phone) + '</h4>'
                + card('المواقع', table(['الموقع','النوع','أصناف','سالب','لم يُجرَد'],
                    d.locations.map(l => '<tr><td>' + esc(l.name) + '</td><td>'
                        + (l.kind === 'warehouse' ? 'مستودع' : 'متجر') + '</td><td>'
                        + esc(l.products) + '</td><td class="'
                        + (l.negative > 0 ? 'text-danger fw-bold' : '') + '">' + esc(l.negative)
                        + '</td><td>' + esc(l.never_counted) + '</td></tr>')))
                + card('التحويلات', table(['المرجع','من','إلى','الحالة','في الطريق'],
                    d.transfers.map(t => '<tr><td class="font-monospace small">' + esc(t.code)
                        + '</td><td>' + esc(t.from) + '</td><td>' + esc(t.to) + '</td><td>'
                        + esc(t.status) + '</td><td>'
                        + (t.days_in_transit != null
                            ? '<span class="text-danger">' + esc(t.days_in_transit) + ' يوماً</span>'
                            : '—') + '</td></tr>')))
                + card('الجرد', table(['المرجع','الموقع','النوع','الحالة','أسطر','لم يُعدّ'],
                    d.counts.map(c => '<tr><td class="font-monospace small">' + esc(c.code)
                        + '</td><td>' + esc(c.location) + '</td><td>' + esc(c.kind)
                        + '</td><td>' + esc(c.status) + '</td><td>' + esc(c.lines)
                        + '</td><td class="' + (c.not_counted > 0 ? 'text-warning' : '')
                        + '">' + esc(c.not_counted) + '</td></tr>')))
                + card('الهالك', table(['الصنف','الكمية','السبب','التكلفة','الحالة','التاريخ'],
                    d.wastes.map(w => '<tr><td>' + esc(w.name) + '</td><td>' + esc(w.quantity)
                        + '</td><td>' + esc(w.reason) + '</td><td>'
                        // **تكلفةٌ مجهولةٌ تُقال، ولا تُعرض صفراً.**
                        + (w.cost != null ? fmt(w.cost) + ' ر.ي'
                            : '<span class="text-warning">غير معروفة</span>')
                        + '</td><td>' + esc(w.status) + '</td><td class="small">'
                        + esc(w.created_at) + '</td></tr>')))
                + card('المرتجعات', table(['البيعة','المبلغ','أسطر','الحالة','التاريخ'],
                    d.returns.map(r => '<tr><td class="font-monospace small">' + esc(r.sale_ulid)
                        + '</td><td>' + fmt(r.total) + ' ر.ي</td><td>' + esc(r.lines)
                        + '</td><td>' + esc(r.status) + '</td><td class="small">'
                        + esc(r.created_at) + '</td></tr>')))
                + card('نسخ الأسعار', table(['الصنف','السعر','الحالة','السريان'],
                    d.prices.map(p => '<tr><td>' + esc(p.product) + '</td><td>'
                        + fmt(p.price) + ' ر.ي</td><td>' + esc(p.status) + '</td><td class="small">'
                        + esc(p.effective_from) + '</td></tr>')));
        } catch (e) {
            box.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        }
    }

    // ── الأحداث ──
    document.getElementById('rc-stuck-days').addEventListener('change', loadStuck);

    let t = null;
    document.getElementById('rc-search').addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(loadMerchants, 350);
    });

    document.getElementById('rc-merchants-body').addEventListener('click', function (e) {
        // **`closest` تصعد من الزرّ إلى صفّه** — يُقرأ الزرُّ وحدَه.
        const btn = e.target.closest('button[data-mid]');
        if (!btn) return;
        const tab = document.querySelector('[data-bs-target="#rc-detail"]');
        if (tab && window.bootstrap) new bootstrap.Tab(tab).show();
        loadMerchant(btn.getAttribute('data-mid'));
    });

    loadOverview();
    loadNegative();
    loadStuck();
    loadMerchants();
})();
</script>
@endsection
