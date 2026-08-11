@extends('layouts.admin.app')

{{--
    AMIAL-MERCHANT-CENTER-001 — مركز التاجر (٣٦٠°).

    **حدّ المسؤولية معروض في الشاشة نفسها**، لا في وثيقة يقرؤها من يبحث:
    التاجر يملك منتجاته ومخزونه وموظفيه، وأميال تملك الحساب والمال
    والعمليات والتسويات والمخاطر والامتثال والاشتراك.

    **وكلّ فعل هنا: تأكيد ← سبب إلزامي ← تنفيذ ← أثر في سجل التدقيق.**
    وزرّ ينفّذ بلا نافذة تأكيد يُضغط سهواً، وفعل بلا سبب لا يُراجَع.
--}}

@section('title', 'مركز التاجر')

@section('content')
<div class="content container-fluid" id="mc" data-testid="merchant-center"
     data-mid="{{ $merchantId }}">

    <div id="mc-head" class="mb-3">
        <div class="card"><div class="card-body text-center p-5">جارٍ التحميل…</div></div>
    </div>

    <div id="mc-banner"></div>

    <ul class="nav nav-tabs mb-3 flex-wrap" role="tablist" id="mc-tabs"></ul>

    <div class="tab-content" id="mc-panes"></div>
</div>

{{-- نافذة السبب — **واحدة لكل الأفعال**، فلا يُنسى السبب في فعل --}}
<div class="modal fade" id="mc-reason-modal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="mc-reason-title">تأكيد الإجراء</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <p id="mc-reason-body" class="mb-3"></p>
      <div id="mc-reason-extra" class="mb-3"></div>
      <label class="form-label"><span id="mc-reason-label">سبب الإجراء</span>
        <span class="text-danger">*</span></label>
      <textarea class="form-control" id="mc-reason-text" rows="3"
                data-testid="mc-reason" placeholder="مثال: تحقيق مخاطر — بلاغ رقم ..."></textarea>
      <div class="form-text">يُسجَّل في سجل التدقيق باسمك ووقتك وعنوانك — ولا يُحذف.</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
      <button class="btn btn-danger" id="mc-reason-confirm" data-testid="mc-confirm">تنفيذ</button>
    </div>
  </div></div>
</div>

<script>
(function () {
    const ROOT = document.getElementById('mc');
    const MID = ROOT.getAttribute('data-mid');
    const B = '{{ url("admin/amial/merchant-center") }}/' + MID;
    const CSRF = '{{ csrf_token() }}';

    const esc = s => String(s ?? '—').replace(/[&<>"']/g,
        m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    const fmt = n => (n === null || n === undefined || n === '')
        ? '—'
        : Number(n).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 2});

    const CACHE = {};

    function banner(msg, kind) {
        document.getElementById('mc-banner').innerHTML =
            '<div class="alert alert-' + kind + '">' + esc(msg) + '</div>';
        window.scrollTo({top: 0, behavior: 'smooth'});
        setTimeout(() => { document.getElementById('mc-banner').innerHTML = ''; }, 5000);
    }

    async function req(path, method, body) {
        const r = await fetch(B + path, {
            method: method || 'GET',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': CSRF},
            body: body ? JSON.stringify(body) : undefined,
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) {
            const err = new Error(j.message || ('تعذّر الطلب (' + r.status + ')'));
            err.code = j.code; err.status = r.status; err.meta = j.meta;
            err.unlock = (j.meta || {}).unlock;   // «كيف يُفتح» يصل مع الرفض لا بعده
            throw err;
        }
        return j.data;
    }

    // ══ نافذة السبب — كلّ فعل يمرّ منها ══
    let pending = null;
    function askReason(title, body, extraHtml, onConfirm, labelText) {
        document.getElementById('mc-reason-title').textContent = title;
        document.getElementById('mc-reason-body').textContent = body;
        document.getElementById('mc-reason-extra').innerHTML = extraHtml || '';
        document.getElementById('mc-reason-text').value = '';
        // نافذةٌ واحدةٌ لكلّ الأفعال، **وعنوانُ الحقل يتبع الفعل**: فتحُ
        // تذكرةٍ موضوعُه هو سببُه، وحقلان يُكتب فيهما الشيءُ نفسه يُملأ
        // أحدُهما بـ«.» ويُفقد المعنى.
        document.getElementById('mc-reason-label').textContent = labelText || 'سبب الإجراء';
        pending = onConfirm;
        new bootstrap.Modal(document.getElementById('mc-reason-modal')).show();
    }

    document.getElementById('mc-reason-confirm').addEventListener('click', async function () {
        const reason = document.getElementById('mc-reason-text').value.trim();
        if (reason.length < 5) {
            // **يُمنع في الواجهة وفي الخادم معاً** — والواجهة وحدها ليست حراسة.
            alert('اكتب سبباً واضحاً (٥ أحرف فأكثر)');
            return;
        }
        const fn = pending;
        bootstrap.Modal.getInstance(document.getElementById('mc-reason-modal')).hide();
        if (fn) { try { await fn(reason); } catch (e) { banner(e.message, 'danger'); } }
    });

    // ══════════════════════════════════════════════════════════════════
    //  الرأس + التبويبات
    // ══════════════════════════════════════════════════════════════════

    async function boot() {
        try {
            const d = await req('/overview');
            CACHE.overview = d;
            renderHead(d);
            renderTabs(d.sections);

            // **يُفتح على التبويب المطلوب** — من جاء من قائمة «فتح المركز ⋮»
            // يريد التسويات، فلا يُنزَل على الملفّ ثمّ يبحث. وتبويبٌ خارج
            // دوره يعود إلى أوّل ما يملك، لا إلى شاشةٍ فارغة.
            const asked = (location.hash.match(/tab=([a-z_]+)/) || [])[1];
            const keys = Object.keys(d.sections);
            const start = (asked && keys.includes(asked)) ? asked : keys[0];

            if (asked && !keys.includes(asked)) {
                banner('قسم «' + esc(asked) + '» خارج دورك — فُتح ' + esc(d.sections[start].name), 'info');
            }

            document.querySelectorAll('#mc-tabs .nav-link').forEach(b =>
                b.classList.toggle('active', b.getAttribute('data-sec') === start));
            show(start);
        } catch (e) {
            document.getElementById('mc-head').innerHTML =
                '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        }
    }

    function renderHead(d) {
        const p = d.profile, r = d.risk, can = d.can || {};

        // **دورٌ لا يرى المال يجب ألّا يرى فراغاً مكانه** (القاعدة ٧):
        // «ليس لك» ليست صفراً، ولا شرطةً، ولا رصيداً فارغاً يُظنّ حقيقة.
        const hasMoney = !!(d.money && !d.money.restricted && d.money.wallet);
        const hasRisk = !!(r && !r.restricted);
        const w = hasMoney ? d.money.wallet : null;

        const riskColor = !hasRisk ? 'light'
            : (r.level === 'high' || r.level === 'critical' ? 'danger'
            : (r.level === 'medium' ? 'warning' : (r.assessed ? 'success' : 'secondary')));

        const moneyBox = hasMoney
            ? ('<div class="fs-3 fw-bold text-primary">'
               + (w.exists ? fmt(w.current) + ' ر.ي' : '<span class="text-muted fs-6">لا محفظة</span>')
               + '</div><div class="small text-muted">محجوز: ' + fmt(w.held)
               + ' · معلّق: ' + fmt(w.pending) + '</div>')
            : ('<div class="small text-muted" data-testid="mc-money-restricted">🔒 '
               + esc((d.money && d.money.note) || 'المركز المالي خارج دورك') + '</div>');

        const riskBadge = hasRisk
            ? ('<span class="badge bg-' + riskColor + '">مخاطر: ' + esc(r.level_ar) + '</span> ')
            : '<span class="badge bg-light text-dark" data-testid="mc-risk-restricted">مخاطر: خارج دورك</span> ';

        // زرُّ التجميد لفريق المخاطر، والتذكرةُ للدعم — ولا يُعرض ما لا يُنفَّذ.
        const actions = []
            .concat(can.freeze ? ['<button class="btn btn-sm btn-outline-danger" data-act="freeze" data-testid="mc-freeze">'
                + (p.status === 'active' ? 'تجميد الحساب' : 'فكّ التجميد') + '</button>',
                '<button class="btn btn-sm btn-outline-warning" data-act="sessions" data-testid="mc-sessions">'
                + 'إنهاء كل الجلسات</button>'] : [])
            .concat(can.ticket ? ['<button class="btn btn-sm btn-outline-primary" data-act="ticket" data-testid="mc-ticket">'
                + 'فتح تذكرة</button>'] : [])
            .concat(['<button class="btn btn-sm btn-outline-secondary" data-act="note" data-testid="mc-note">'
                + 'ملاحظة</button>']);

        document.getElementById('mc-head').innerHTML =
            '<div class="card"><div class="card-body">'
            + '<div class="d-flex align-items-start gap-3 flex-wrap">'
            + '<div><h2 class="mb-1">' + esc(p.store_name || p.name) + '</h2>'
            + '<div class="text-muted small">' + esc(p.name) + ' · ' + esc(p.phone)
            + ' · #' + esc(p.merchant_number || p.id) + '</div>'
            + '<div class="mt-2">'
            + '<span class="badge bg-' + (p.status === 'active' ? 'success' : 'danger') + '">'
              + esc(p.status_ar) + '</span> '
            + '<span class="badge bg-info">' + esc(p.business_type_label) + '</span> '
            + '<span class="badge bg-primary">' + esc(p.plan_name) + '</span> '
            + riskBadge
            + '<span class="badge bg-secondary">' + esc(p.kyc_status) + '</span>'
            + '</div></div>'
            + '<div class="ms-auto text-end">'
            + moneyBox
            + '<div class="btn-group mt-2">' + actions.join('') + '</div>'
            + '</div></div>'
            + '<div class="alert alert-light border mt-3 mb-0 small">'
            + '<strong>حدّ المسؤولية:</strong> يملكه التاجر — ' + esc(d.boundary.owned_by_merchant)
            + ' · تملكه أميال — ' + esc(d.boundary.owned_by_amial)
            + '</div>'
            + '<div class="small text-muted mt-2" data-testid="mc-my-roles">دورُك: '
              + esc((d.my_roles || []).join(' · ') || 'بلا دور')
              + ' — وما لا تراه ليس ناقصاً، هو خارج دورك.</div>'
            + '</div></div>';
    }

    function renderTabs(sections) {
        const keys = Object.keys(sections);
        document.getElementById('mc-tabs').innerHTML = keys.map((k, i) =>
            '<li class="nav-item"><button class="nav-link' + (i === 0 ? ' active' : '')
            + '" data-sec="' + k + '" data-testid="mc-tab-' + k + '">'
            + esc(sections[k].name) + '</button></li>').join('');

        document.getElementById('mc-panes').innerHTML = keys.map((k, i) =>
            '<div class="mc-pane' + (i === 0 ? '' : ' d-none') + '" id="pane-' + k + '">'
            + '<div class="card"><div class="card-body text-center p-5 text-muted">'
            + 'اضغط التبويب لعرضه</div></div></div>').join('');
    }

    document.getElementById('mc-tabs').addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-sec]');
        if (!btn) return;
        document.querySelectorAll('#mc-tabs .nav-link').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        show(btn.getAttribute('data-sec'));
    });

    function pane(sec) { return document.getElementById('pane-' + sec); }

    function show(sec) {
        document.querySelectorAll('.mc-pane').forEach(p => p.classList.add('d-none'));
        const el = pane(sec);
        el.classList.remove('d-none');
        LOADERS[sec] ? LOADERS[sec](el) : el.innerHTML = '<div class="alert alert-warning">قسم غير مبني</div>';
    }

    // ══ أدوات عرض ══
    const card = (title, inner, footer) =>
        '<div class="card mb-3"><div class="card-header"><h4 class="card-title mb-0">'
        + esc(title) + '</h4></div>' + inner
        + (footer ? '<div class="card-footer small text-muted">' + footer + '</div>' : '')
        + '</div>';

    const table = (heads, rows, empty) =>
        '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
        + '<thead class="thead-light"><tr>' + heads.map(h => '<th>' + esc(h) + '</th>').join('')
        + '</tr></thead><tbody>'
        + (rows.length ? rows.join('')
            : '<tr><td colspan="' + heads.length + '" class="text-center text-muted p-4">'
              + esc(empty || 'لا سجلات') + '</td></tr>')
        + '</tbody></table></div>';

    const kv = (label, value) =>
        '<div class="col-md-4 mb-2"><div class="small text-muted">' + esc(label) + '</div>'
        + '<div class="fw-bold">' + esc(value) + '</div></div>';

    const stat = (label, value, color, act) =>
        '<div class="col-6 col-lg-3 mb-2"><div class="card h-100' + (act ? ' cursor-pointer' : '')
        + '"' + (act ? ' data-drill="' + act + '"' : '') + '><div class="card-body p-3">'
        + '<div class="small text-muted">' + esc(label) + '</div>'
        + '<div class="fs-5 fw-bold text-' + (color || 'dark') + '">' + value + '</div>'
        + (act ? '<div class="small text-primary">اضغط للتفصيل ←</div>' : '')
        + '</div></div></div>';

    const loading = el => { el.innerHTML =
        '<div class="card"><div class="card-body text-center p-5">جارٍ التحميل…</div></div>'; };

    const failed = (el, e) => { el.innerHTML =
        '<div class="alert alert-danger">' + esc(e.message)
        + '<button class="btn btn-sm btn-outline-danger ms-2" onclick="location.reload()">إعادة</button></div>'; };

    // ══════════════════════════════════════════════════════════════════
    //  محمّلات الأقسام
    // ══════════════════════════════════════════════════════════════════

    const LOADERS = {

        profile(el) {
            const p = CACHE.overview.profile, u = CACHE.overview.pulse;
            el.innerHTML =
                card('البيانات الأساسية',
                    '<div class="card-body"><div class="row">'
                    + kv('الاسم', p.name) + kv('المتجر', p.store_name)
                    + kv('الهاتف', p.phone) + kv('البريد', p.email)
                    + kv('النشاط', p.business_type_label) + kv('المنطقة', p.zone_code)
                    + kv('تاريخ الإنشاء', p.created_at) + kv('آخر دخول', p.last_login)
                    + kv('آخر عملية', p.last_transaction)
                    + kv('التوثيق', p.kyc_status) + kv('مستوى التحقق', p.verification_level)
                    + kv('الباقة', p.plan_name + ' — تنتهي ' + (p.plan_expires || 'بلا تاريخ'))
                    + '</div></div>')
                + card('الفروع ونقاط البيع (بيانات تشغيلية)',
                    table(['الفرع', 'الرمز', 'المدينة', 'الحالة'],
                        p.branches.map(b => '<tr><td>' + esc(b.name) + '</td><td>' + esc(b.code)
                            + '</td><td>' + esc(b.city) + '</td><td>'
                            + (b.active ? '<span class="badge bg-success">نشط</span>'
                                        : '<span class="badge bg-secondary">مغلق</span>')
                            + '</td></tr>'), 'لا فروع مسجّلة'),
                    'أميال ترى الفروع كبيانات تشغيلية — ولا تدير عملياتها الداخلية.')
                + card('النبض',
                    '<div class="card-body"><div class="row">'
                    + ['today','week','month','quarter'].map(k => stat(
                        {today:'اليوم',week:'٧ أيام',month:'٣٠ يوماً',quarter:'٩٠ يوماً'}[k],
                        u[k].count + ' عملية<div class="small">' + fmt(u[k].amount) + ' ر.ي</div>',
                        'primary')).join('')
                    + '</div>'
                    // **«لا عمليّات» ليست «متوسّط صفر»** — القسمة على صفر تُقال.
                    + '<div class="small text-muted mt-2">متوسّط العملية (٣٠ يوماً): '
                      + (u.month.average !== null ? fmt(u.month.average) + ' ر.ي'
                          : 'غير محسوب — لا عمليات في المدة') + '</div>'
                    + '</div></div>');
        },

        async money(el) {
            loading(el);
            try {
                const d = await req('/money');
                const t = d.totals;
                el.innerHTML =
                    '<div class="row mb-3">'
                    + stat('المقبوضات', fmt(t.received.amount) + ' ر.ي', 'success', 'merchant_payment')
                    + stat('المدفوعات', fmt(t.paid.amount) + ' ر.ي', 'danger', 'send_money')
                    + stat('إيداع نقدي', fmt(t.cash_in.amount) + ' ر.ي', 'info', 'cash_in')
                    + stat('سحب نقدي', fmt(t.cash_out.amount) + ' ر.ي', 'warning', 'cash_out')
                    + stat('مرتجعات', fmt(t.refunds.amount) + ' ر.ي', 'secondary', 'refund')
                    + stat('رسوم أميال المحصّلة', fmt(d.platform_fees_paid) + ' ر.ي', 'dark')
                    + stat('عدد مبيعاته', d.sales_summary.count, 'primary')
                    + stat('قيمة مبيعاته', fmt(d.sales_summary.amount) + ' ر.ي', 'primary')
                    + '</div>'
                    + '<div class="alert alert-light border small">' + esc(d.sales_summary.note) + '</div>'
                    + card('كشف الحساب — بين أميال والتاجر',
                        '<div class="card-body pb-0"><div class="row g-2 align-items-end mb-2">'
                        + '<div class="col-auto"><label class="form-label small mb-0">من</label>'
                        + '<input type="date" class="form-control form-control-sm" id="mc-from"></div>'
                        + '<div class="col-auto"><label class="form-label small mb-0">إلى</label>'
                        + '<input type="date" class="form-control form-control-sm" id="mc-to"></div>'
                        + '<div class="col-auto"><button class="btn btn-sm btn-primary"'
                        + ' id="mc-stmt-go" data-testid="mc-stmt-go">عرض</button></div>'
                        + '</div></div><div id="mc-stmt"></div>',
                        'قيود بين أميال والتاجر — لا محاسبة منتجاته.');
                loadStatement();
            } catch (e) { failed(el, e); }
        },

        async settlements(el) {
            loading(el);
            try {
                const d = await req('/settlements');
                if (!d.available) {
                    el.innerHTML = '<div class="alert alert-warning">' + esc(d.reason) + '</div>';
                    return;
                }
                el.innerHTML = card('التسويات',
                    table(['المرجع','المبلغ','الحالة','أنشأها','اعتمدها','الاعتماد الثاني','مرجع التحويل','اكتملت'],
                        d.rows.map(s => '<tr><td class="font-monospace small">' + esc(s.reference)
                            + '</td><td class="fw-bold">' + fmt(s.amount) + ' ' + esc(s.currency)
                            + '</td><td>' + esc(s.status) + '</td><td>' + esc(s.created_by)
                            + '</td><td>' + esc(s.approved_by)
                            // **الموافقة المزدوجة تُقرأ هنا** — وغيابها إشارة.
                            + '</td><td>' + (s.second_approved_by
                                ? esc(s.second_approved_by)
                                : '<span class="text-warning">لم تُعتمد ثانياً</span>')
                            + '</td><td class="small">' + esc(s.external_ref)
                            + '</td><td class="small">' + esc(s.completed_at) + '</td></tr>'),
                        'لا تسويات لهذا التاجر'),
                    'الرصيد قبل وبعد محفوظ لكل تسوية — وبه تُطابق الأرقام.');
            } catch (e) { failed(el, e); }
        },

        async operations(el) {
            loading(el);
            try {
                const d = await req('/operations?days=30');
                el.innerHTML = card('العمليات — آخر ' + d.days + ' يوماً',
                    table(['النوع','العدد','القيمة','الرسوم',''],
                        d.by_type.map(r => '<tr><td>' + esc(r.type) + '</td><td>' + r.count
                            + '</td><td>' + fmt(r.amount) + ' ر.ي</td><td>' + fmt(r.fees)
                            + '</td><td><button class="btn btn-sm btn-outline-primary"'
                            + ' data-drill="' + esc(r.type) + '" data-testid="mc-drill">التفصيل</button>'
                            + '</td></tr>'), 'لا عمليات في المدة'),
                    'اضغط «التفصيل» لعرض عمليات النوع — ومنها إلى العملية نفسها.')
                + '<div id="mc-drill-box"></div>';
            } catch (e) { failed(el, e); }
        },

        async risk(el) {
            loading(el);
            try {
                const d = await req('/risk');
                el.innerHTML =
                    card('درجة المخاطر',
                        '<div class="card-body"><div class="row">'
                        + kv('الدرجة', d.assessed ? d.score : 'لم تُقيَّم بعد')
                        + kv('المستوى', d.level_ar)
                        + kv('متوسط الحجم اليومي', fmt(d.avg_daily_volume))
                        + kv('ذروة الحجم اليومي', fmt(d.peak_daily_volume))
                        + kv('إشارات غسل الأموال', d.aml_flags)
                        + kv('آخر مراجعة', d.last_reviewed_at || 'لم تُراجَع')
                        + '</div>'
                        // AMIAL-RISK-TIER-DOOR-001 — الفعلُ كان مُعلَناً في
                        // سجلّ التدقيق بلا مسارٍ ولا زرّ. **وفعلٌ لا يُضغط
                        // ليس مبنيّاً** (القاعدة التاسعة).
                        + '<div class="mt-3 pt-3 border-top">'
                        + '<div class="small text-muted mb-2">تغييرُ الدرجة يشدّد '
                        + 'فحصَ التاجر ويؤخّر تسوياته — قرارٌ بسببٍ وأثر.</div>'
                        + ['low','medium','high','critical'].map(function (lv) {
                            const label = {low:'منخفض', medium:'متوسط',
                                           high:'مرتفع', critical:'حرِج'}[lv];
                            const on = d.level === lv;
                            return '<button class="btn btn-sm me-1 '
                                + (on ? 'btn-dark' : 'btn-outline-secondary')
                                + '" data-act="risk-tier" data-level="' + lv + '"'
                                + ' data-testid="mc-tier-' + lv + '"'
                                + (on ? ' disabled' : '') + '>' + label + '</button>';
                          }).join('')
                        + '</div></div>',
                        d.assessed ? '' : '<strong>«لم تُقيَّم» ليست «منخفضة»</strong> — '
                            + 'غياب التقييم ليس شهادة أمان.')
                    + card('أحداث المخاطر',
                        table(['النوع','الأثر','الوصف','العملية','الوقت'],
                            d.events.map(e2 => '<tr><td>' + esc(e2.type) + '</td><td>'
                                + esc(e2.contribution) + '</td><td>' + esc(e2.description)
                                + '</td><td class="font-monospace small">' + esc(e2.transaction_ulid)
                                + '</td><td class="small">' + esc(e2.at) + '</td></tr>'),
                            'لا أحداث مخاطر مسجّلة'));
            } catch (e) { failed(el, e); }
        },

        async staff(el) {
            loading(el);
            try {
                const d = await req('/staff');
                el.innerHTML =
                    '<div class="row mb-3">'
                    + stat('الموظفون', d.total, 'primary')
                    + stat('النشطون', d.active, 'success')
                    + stat('المعطَّلون', d.disabled, 'secondary')
                    + '</div>'
                    + '<div class="alert alert-light border small">' + esc(d.note) + '</div>'
                    + card('الموظفون',
                        table(['الموظف','رقم النقطة','الفرع','الحالة','عملياته','آخر عملية','آخر دخول',''],
                            d.rows.map(s => '<tr><td>' + esc(s.name) + '</td><td>' + esc(s.pos_number)
                                + '</td><td>' + esc(s.branch) + '</td><td>'
                                + (s.active ? '<span class="badge bg-success">نشط</span>'
                                            : '<span class="badge bg-secondary">معطَّل</span>')
                                + '</td><td>' + s.transactions + '</td><td class="small">'
                                + esc(s.last_transaction) + '</td><td class="small">'
                                + esc(s.last_login) + '</td><td>'
                                + (s.active
                                    ? '<button class="btn btn-sm btn-outline-danger"'
                                      + ' data-act="staff-disable" data-pos="' + s.id + '"'
                                      + ' data-name="' + esc(s.name) + '" data-testid="mc-staff-disable">'
                                      + 'تعطيل أمني</button>'
                                    : '<span class="small text-muted">إعادته شأن التاجر</span>')
                                + '</td></tr>'), 'لا موظفين'),
                        '<strong>أميال لا تنشئ الأدوار ولا تمنحها</strong> — '
                        + 'والتعطيل الأمني إجراء استثنائي يُسجَّل، وإعادة التفعيل شأن التاجر.');
            } catch (e) { failed(el, e); }
        },

        async devices(el) {
            loading(el);
            try {
                const d = await req('/devices');
                el.innerHTML =
                    '<div class="row mb-3">'
                    + stat('الأجهزة', d.total, 'primary')
                    + stat('نشطة', d.active, 'success')
                    + stat('محظورة', d.blocked, 'danger')
                    + '</div>'
                    + card('الأجهزة والجلسات',
                        table(['الجهاز','النظام','الإصدار','IP','آخر اتصال','الحالة',''],
                            d.rows.map(v => '<tr><td>' + esc(v.model || v.device_id)
                                + '<div class="small text-muted">#' + esc(v.user_id) + '</div>'
                                + '</td><td>' + esc(v.os) + '</td><td>' + esc(v.app_version)
                                + '</td><td class="font-monospace small">' + esc(v.ip)
                                + '</td><td class="small">' + esc(v.last_seen) + '</td><td>'
                                + (v.blocked ? '<span class="badge bg-danger">محظور</span>'
                                    : (v.trusted ? '<span class="badge bg-success">موثوق</span>'
                                                 : '<span class="badge bg-secondary">عادي</span>'))
                                + (v.block_reason ? '<div class="small text-muted">'
                                    + esc(v.block_reason) + '</div>' : '')
                                + '</td><td><button class="btn btn-sm btn-outline-'
                                + (v.blocked ? 'success' : 'danger') + '"'
                                + ' data-act="device" data-device="' + v.id + '"'
                                + ' data-blocked="' + (v.blocked ? '1' : '0') + '"'
                                + ' data-testid="mc-device-toggle">'
                                + (v.blocked ? 'رفع الحظر' : 'حظر') + '</button></td></tr>'),
                            'لا أجهزة مسجّلة'),
                        'تشمل أجهزة التاجر وموظفيه — ومنها تبدأ التحقيقات.');
            } catch (e) { failed(el, e); }
        },

        async subscription(el) {
            loading(el);
            try {
                const d = await req('/subscription');
                el.innerHTML =
                    '<div class="row mb-3">'
                    + stat('الباقة', esc(d.plan_name), 'primary')
                    + stat('السعر الشهري', fmt(d.price_monthly) + ' ر.س', 'dark')
                    + stat('تنتهي', esc(d.expires_at || 'بلا تاريخ'), 'secondary')
                    + stat('قدرات متاحة', d.summary.available, 'success')
                    + '</div>'
                    + card('تغيير الباقة',
                        '<div class="card-body"><div class="row g-2 align-items-end">'
                        + '<div class="col-auto"><label class="form-label small mb-0">الباقة</label>'
                        + '<select class="form-select form-select-sm" id="mc-plan" data-testid="mc-plan">'
                        + d.available_plans.map(p => '<option value="' + esc(p.code) + '"'
                            + (p.code === d.plan ? ' selected' : '') + '>' + esc(p.name)
                            + ' — ' + p.price + ' ر.س</option>').join('')
                        + '</select></div>'
                        + '<div class="col-auto"><label class="form-label small mb-0">المدة (شهر)</label>'
                        + '<input type="number" class="form-control form-control-sm" id="mc-months"'
                        + ' value="1" min="0" max="36" style="width:110px"></div>'
                        + '<div class="col-auto"><button class="btn btn-sm btn-primary"'
                        + ' data-act="plan" data-testid="mc-plan-save">حفظ</button></div>'
                        + '</div></div>',
                        'صفر شهراً = بلا تاريخ انتهاء. والتغيير يُسجَّل بسببه في سجل التدقيق.')
                    + '<div class="alert alert-info small">تفصيل ما تفتحه كل باقة في '
                    + '<a href="{{ route('admin.amial.entitlements.page') }}">مركز الباقات والقدرات</a>.</div>';
            } catch (e) { failed(el, e); }
        },

        async compliance(el) {
            loading(el);
            try {
                const d = await req('/compliance');
                el.innerHTML =
                    card('حالة التوثيق',
                        '<div class="card-body"><div class="row">'
                        + kv('حالة KYC', d.kyc_status) + kv('مستوى التحقق', d.verification_level)
                        + kv('تاريخ التوثيق', d.verified_at || 'لم يُوثَّق')
                        + '</div></div>')
                    + card('الوثائق',
                        table(['النوع','الحالة','رُفعت','رُوجعت','تنتهي','سبب الرفض'],
                            d.documents.map(v => '<tr><td>' + esc(v.type) + '</td><td>'
                                + esc(v.status) + '</td><td class="small">' + esc(v.uploaded_at)
                                + '</td><td class="small">' + esc(v.reviewed_at || 'لم تُراجَع')
                                + '</td><td class="small">' + esc(v.expires_at)
                                + '</td><td class="small text-danger">' + esc(v.rejection_reason || '')
                                + '</td></tr>'), 'لا وثائق مرفوعة'),
                        'الامتثال قسم مستقل عن الاشتراك — ولا يُخلطان.');
            } catch (e) { failed(el, e); }
        },

        async support(el) {
            loading(el);
            try {
                const d = await req('/support');
                CACHE.support = d;   // التصنيفات والأولويّات تُملأ منها نافذةُ الفتح
                const canTicket = !!(CACHE.overview.can || {}).ticket;
                el.innerHTML =
                    '<div class="row mb-3">'
                    + stat('تذاكر مفتوحة', d.open, d.open > 0 ? 'warning' : 'success')
                    + stat('إجمالي التذاكر', d.total, 'secondary')
                    + '</div>'
                    + (canTicket
                        ? '<button class="btn btn-primary mb-3" data-act="ticket"'
                          + ' data-testid="mc-ticket-tab">فتح تذكرة جديدة</button>'
                        : '<div class="alert alert-light border small mb-3">'
                          + 'فتحُ التذاكر لفريق الدعم — دورُك لا يشمله</div>')
                    + card('التذاكر',
                        table(['الرقم','الموضوع','التصنيف','الأولوية','الحالة','التاريخ'],
                            d.rows.map(t => '<tr><td class="font-monospace small">'
                                + esc(t.number) + '</td><td>' + esc(t.subject) + '</td><td>'
                                + esc(t.category) + '</td><td>' + esc(t.priority) + '</td><td>'
                                + esc(t.status) + '</td><td class="small">' + esc(t.at)
                                + '</td></tr>'), 'لا تذاكر'));
            } catch (e) { failed(el, e); }
        },

        async audit(el) {
            loading(el);
            try {
                const d = await req('/audit');
                el.innerHTML =
                    card('أذونات الاطّلاع على التفصيل التشغيلي',
                        '<div class="card-body pb-2">'
                        + '<button class="btn btn-sm btn-outline-primary mb-3" data-act="grant"'
                        + ' data-testid="mc-grant">فتح إذن اطّلاع مؤقّت</button>'
                        + '<div id="mc-opdetail"></div></div>'
                        + table(['المرجع','النطاق','السبب','ينتهي','الاستعمال','الحالة',''],
                            d.grants.map(g => '<tr><td class="font-monospace small">'
                                + esc(g.reference) + '</td><td>' + esc(g.scope_ar) + '</td><td>'
                                + esc(g.reason) + '</td><td class="small">' + esc(g.expires_at)
                                + '</td><td>' + g.use_count + ' مرة</td><td>'
                                + (g.active ? '<span class="badge bg-success">سارٍ</span>'
                                            : '<span class="badge bg-secondary">منتهٍ</span>')
                                + '</td><td>' + (g.active
                                    ? '<button class="btn btn-sm btn-outline-danger" data-act="revoke-grant"'
                                      + ' data-grant="' + g.id + '">إلغاء</button>' : '')
                                + '</td></tr>'), 'لا أذونات — ولا اطّلاع على تفصيل التاجر'),
                        '<strong>أميال لا ترى أصناف التاجر ولا مخزونه بلا إذن.</strong> '
                        + 'والإذن بسبب وأجل، ويُسجَّل استعماله.')
                    + card('سجل التدقيق — كل فعل إداري على هذا التاجر',
                        table(['المرجع','الإجراء','التغيّر','المنفّذ','السبب','IP','النتيجة','الوقت'],
                            d.actions.map(a => '<tr class="' + (a.result === 'failed' ? 'table-danger' : '')
                                + '"><td class="font-monospace small">' + esc(a.reference)
                                + '</td><td>' + esc(a.action_ar) + '</td><td class="small">'
                                + esc(a.transition || '') + '</td><td>' + esc(a.actor)
                                + '</td><td class="small">' + esc(a.reason)
                                + '</td><td class="font-monospace small">' + esc(a.ip) + '</td><td>'
                                + (a.result === 'ok' ? '<span class="badge bg-success">نُفّذ</span>'
                                    : '<span class="badge bg-danger">فشل</span>'
                                      + '<div class="small">' + esc(a.failure || '') + '</div>')
                                + '</td><td class="small">' + esc(a.at) + '</td></tr>'),
                            'لا إجراءات إدارية على هذا التاجر'),
                        '<strong>المحاولات الفاشلة مسجّلة أيضاً</strong> — '
                        + 'وسجلّ لا يحفظ إلّا النجاح يُخفي نصف ما جرى. ولا يُحذف منه شيء.');
            } catch (e) { failed(el, e); }
        },
    };

    // ══════════════════════════════════════════════════════════════════
    //  التعمّق
    // ══════════════════════════════════════════════════════════════════

    async function loadStatement() {
        const box = document.getElementById('mc-stmt');
        if (!box) return;
        const from = (document.getElementById('mc-from') || {}).value || '';
        const to = (document.getElementById('mc-to') || {}).value || '';
        box.innerHTML = '<div class="text-center p-4 text-muted">جارٍ التحميل…</div>';
        try {
            const d = await req('/statement?from=' + from + '&to=' + to);
            box.innerHTML = table(['المرجع','النوع','مدين','دائن','رسوم','الرصيد بعد','الموظف','الوقت'],
                d.rows.map(r => '<tr><td class="font-monospace small">' + esc(r.transaction_id)
                    + '</td><td>' + esc(r.type) + '</td><td class="text-danger">' + fmt(r.debit)
                    + '</td><td class="text-success">' + fmt(r.credit) + '</td><td>' + fmt(r.charge)
                    + '</td><td>' + fmt(r.balance_after) + '</td><td>'
                    + (r.pos_user_id ? '#' + esc(r.pos_user_id) : '—')
                    + '</td><td class="small">' + esc(r.at) + '</td></tr>'), 'لا قيود في المدة');
        } catch (e) { box.innerHTML = '<div class="alert alert-danger m-3">' + esc(e.message) + '</div>'; }
    }

    async function drill(type) {
        const box = document.getElementById('mc-drill-box');
        if (!box) return;
        box.innerHTML = '<div class="card"><div class="card-body text-center p-4">جارٍ التحميل…</div></div>';
        try {
            const d = await req('/operations/' + encodeURIComponent(type));
            box.innerHTML = card('عمليات: ' + type,
                table(['المرجع','المبلغ','الرسوم','من','إلى','الموظف','القرار','الوقت'],
                    d.rows.map(r => '<tr><td class="font-monospace small">' + esc(r.transaction_id)
                        + '</td><td class="fw-bold">' + fmt(r.amount) + '</td><td>' + fmt(r.charge)
                        + '</td><td>' + esc(r.from_user_id) + '</td><td>' + esc(r.to_user_id)
                        + '</td><td>' + (r.pos_user_id ? '#' + esc(r.pos_user_id) : '—')
                        + '</td><td>' + esc(r.decision) + '</td><td class="small">' + esc(r.at)
                        + '</td></tr>'), 'لا عمليات من هذا النوع'),
                'الموظف المنفّذ داخل التاجر مسجَّل — وهو ما تحتاجه أميال، لا اسم الصنف المباع.');
        } catch (e) { box.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; }
    }

    async function loadOperationalDetail() {
        const box = document.getElementById('mc-opdetail');
        if (!box) return;
        try {
            const d = await req('/operational-detail');
            box.innerHTML = '<div class="alert alert-success small">'
                + 'إذن سارٍ — أصناف: ' + d.products + ' · مواقع: ' + d.locations
                + ' · صفوف مخزون سالب: ' + d.negative_stock_rows
                + '<div class="mt-1">' + esc(d.note) + '</div></div>';
        } catch (e) {
            // **رفضٌ يقول كيف يُفتح**: ٤٢٣ تعني «لك الحقّ ولا إذن»، فيُعرض
            // معها طريقُ الخروج. و٤٠٣ تعني «ليس من عملك»، فلا يُعرض زرٌّ
            // يُضغط ليردّ ٤٠٣ مرّةً أخرى.
            box.innerHTML = e.status === 423
                ? ('<div class="alert alert-warning small">' + esc(e.message)
                   + '<div class="mt-2">' + esc(e.unlock || '')
                   + ' <button class="btn btn-sm btn-warning ms-2" data-act="grant"'
                   + ' data-testid="mc-grant-inline">فتح إذن اطّلاع مؤقّت</button></div></div>')
                : ('<div class="alert alert-secondary small">' + esc(e.message) + '</div>');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  الأفعال
    // ══════════════════════════════════════════════════════════════════

    document.addEventListener('click', async function (e) {
        // **`closest` من الزرّ** — وصعودُها إلى الصفّ تبتلع الضغطة.
        const drillBtn = e.target.closest('[data-drill]');
        if (drillBtn) { drill(drillBtn.getAttribute('data-drill')); return; }

        if (e.target.closest('#mc-stmt-go')) { loadStatement(); return; }

        const btn = e.target.closest('[data-act]');
        if (!btn) return;

        const act = btn.getAttribute('data-act');

        if (act === 'freeze') {
            const p = CACHE.overview.profile;
            askReason(p.status === 'active' ? 'تجميد الحساب' : 'فكّ التجميد',
                p.status === 'active'
                    ? 'سيتوقّف التاجر عن كل العمليات فوراً. أرصدته لا تُمسّ.'
                    : 'سيعود التاجر للعمل فوراً.',
                '', async reason => {
                    await req('/freeze', 'POST', {reason});
                    banner('نُفّذ — والأثر في سجل التدقيق', 'success');
                    boot();
                });
            return;
        }

        if (act === 'sessions') {
            askReason('إنهاء كل الجلسات',
                'ستُنهى جلسات التاجر وكل موظفيه، ويحتاجون تسجيل دخول جديد.',
                '', async reason => {
                    const d = await req('/revoke-sessions', 'POST', {reason});
                    banner('أُنهيت ' + d.revoked + ' جلسة', 'success');
                });
            return;
        }

        if (act === 'risk-tier') {
            const lv = btn.getAttribute('data-level');
            const label = {low:'منخفض', medium:'متوسط', high:'مرتفع', critical:'حرِج'}[lv];

            askReason('تغيير درجة المخاطر إلى «' + label + '»',
                'يُشدَّد فحصُ التاجر وقد تتأخّر تسوياته. والدرجةُ قبل وبعد '
                + 'تُسجَّلان في سجل التدقيق باسمك.',
                '', async reason => {
                    await req('/risk-tier', 'POST', {level: lv, reason});
                    banner('تغيّرت الدرجة — والأثر في سجل التدقيق', 'success');
                    show('risk');
                });
            return;
        }

        if (act === 'ticket') {
            // **التذكرةُ تُفتح من هنا لا من شاشةٍ أخرى**: من يقرأ شكوى
            // تاجرٍ في مركزه يفتحها وهو داخل السياق. وخروجُه للبحث عن
            // الحساب مرّةً ثانية يُضيّع ما فتح المركز من أجله.
            const cats = (CACHE.support && CACHE.support.categories) || ['other'];
            const pris = (CACHE.support && CACHE.support.priorities) || ['normal'];
            const CAT_AR = {
                missing_transfer: 'تحويل لم يصل', wrong_recipient: 'مستلم خاطئ',
                forgot_pin: 'نسي الرمز', fraud_suspect: 'اشتباه احتيال',
                account_access: 'تعذّر الدخول', balance_issue: 'مشكلة رصيد', other: 'أخرى',
            };
            const PRI_AR = {low: 'منخفضة', normal: 'عادية', high: 'مرتفعة', urgent: 'عاجلة'};

            askReason('فتح تذكرة دعم للتاجر',
                'تُفتح باسمك وتظهر في قسم الدعم وفي سجل التدقيق.',
                '<div class="row g-2">'
                + '<div class="col-6"><label class="form-label small">التصنيف</label>'
                + '<select class="form-select form-select-sm" id="mc-tkt-cat" data-testid="mc-tkt-cat">'
                + cats.map(c => '<option value="' + c + '">' + esc(CAT_AR[c] || c) + '</option>').join('')
                + '</select></div>'
                + '<div class="col-6"><label class="form-label small">الأولوية</label>'
                + '<select class="form-select form-select-sm" id="mc-tkt-pri" data-testid="mc-tkt-pri">'
                + pris.map(p => '<option value="' + p + '"' + (p === 'normal' ? ' selected' : '') + '>'
                    + esc(PRI_AR[p] || p) + '</option>').join('')
                + '</select></div>'
                + '<div class="col-12"><label class="form-label small">التفاصيل (اختياري)</label>'
                + '<textarea class="form-control form-control-sm" id="mc-tkt-desc" rows="2"></textarea></div>'
                + '</div>',
                async subject => {
                    const d = await req('/ticket', 'POST', {
                        subject: subject,
                        category: document.getElementById('mc-tkt-cat').value,
                        priority: document.getElementById('mc-tkt-pri').value,
                        description: document.getElementById('mc-tkt-desc').value.trim() || null,
                    });
                    banner('فُتحت التذكرة ' + d.ticket_number, 'success');
                    show('support');
                },
                'موضوع التذكرة');
            return;
        }

        if (act === 'note') {
            askReason('ملاحظة إدارية', 'تُسجَّل في سجل التدقيق باسمك ولا تُحذف.',
                '', async reason => {
                    await req('/note', 'POST', {reason});
                    banner('أُضيفت الملاحظة', 'success');
                });
            return;
        }

        if (act === 'staff-disable') {
            askReason('تعطيل موظف لأمر أمني',
                'تعطيل «' + btn.getAttribute('data-name') + '». '
                + 'إعادة تفعيله شأن التاجر لا أميال.',
                '', async reason => {
                    await req('/staff/' + btn.getAttribute('data-pos') + '/disable', 'POST', {reason});
                    banner('عُطّل الموظف', 'success');
                    show('staff');
                });
            return;
        }

        if (act === 'device') {
            const blocked = btn.getAttribute('data-blocked') === '1';
            askReason(blocked ? 'رفع الحظر عن الجهاز' : 'حظر الجهاز',
                blocked ? 'سيعود الجهاز للعمل.' : 'لن يستطيع هذا الجهاز الدخول.',
                '', async reason => {
                    await req('/devices/' + btn.getAttribute('data-device') + '/toggle', 'POST', {reason});
                    banner('نُفّذ', 'success');
                    show('devices');
                });
            return;
        }

        if (act === 'plan') {
            const plan = document.getElementById('mc-plan').value;
            const months = document.getElementById('mc-months').value;
            askReason('تغيير الباقة',
                'ستتغيّر قدرات التاجر فوراً بحسب الباقة الجديدة.',
                '', async reason => {
                    await req('/plan', 'POST', {reason, plan, months});
                    banner('تغيّرت الباقة', 'success');
                    show('subscription');
                    boot();
                });
            return;
        }

        if (act === 'grant') {
            askReason('فتح إذن اطّلاع على التفصيل التشغيلي',
                'سترى أعداد أصنافه ومواقعه ومخزونه السالب — مؤقّتاً وبأثر مسجَّل.',
                '<label class="form-label small">المدة (ساعة)</label>'
                + '<input type="number" class="form-control form-control-sm" id="mc-hours"'
                + ' value="4" min="1" max="24">'
                + '<label class="form-label small mt-2">مرجع التذكرة (اختياري)</label>'
                + '<input type="text" class="form-control form-control-sm" id="mc-ticket">',
                async reason => {
                    const hours = (document.getElementById('mc-hours') || {}).value || 4;
                    const ticket = (document.getElementById('mc-ticket') || {}).value || null;
                    const d = await req('/access', 'POST',
                        {reason, hours, ticket_ref: ticket, scope: 'operational'});
                    banner('فُتح الإذن حتى ' + d.expires_at, 'success');
                    show('audit');
                    setTimeout(loadOperationalDetail, 400);
                });
            return;
        }

        if (act === 'revoke-grant') {
            askReason('إلغاء الإذن', 'سيتوقّف الاطّلاع فوراً.', '', async reason => {
                await req('/access/' + btn.getAttribute('data-grant'), 'DELETE', {reason});
                banner('أُلغي الإذن', 'success');
                show('audit');
            });
            return;
        }
    });

    boot();
})();
</script>
@endsection
