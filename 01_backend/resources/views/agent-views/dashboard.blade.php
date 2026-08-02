<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>بوّابة الوكيل — أميال باي</title>
    {{-- كان هنا `asset('public/assets/admin/css/theme.min.css')` — ملفٌّ لا
         وجود له في المستودع. فكانت اللوحة تُحمَّل بلا Bootstrap فتظهر نصّاً
         خاماً بنقاطٍ سوداء، وبلا سكربته فلا تتبدّل التبويبات ولا تُفتح
         النوافذ. وهو ما بدا «بدائياً» و«أزراراً لا تعمل». --}}
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <style>
        body { background:#f4f6fa; font-family:'Tajawal',system-ui,sans-serif; }
        .topbar { background:#0f1b2d; color:#fff; }
        .money { font-variant-numeric:tabular-nums; }
        .cash { color:#0b7a3b; } .emoney { color:#0d5bd6; }
    </style>
</head>
<body>

{{--
    AMIAL-AGENT-PORTAL-001 — لوحة الوكيل.

    **الرصيدان يُعرَضان جنباً إلى جنب في كلّ مكان — وهذا ليس تفصيلاً بصرياً.**

    للفرع قيدان يتحرّكان في اتّجاهين متعاكسين: النقد الورقيّ يحدّ **السحب**،
    والرصيد الإلكترونيّ يحدّ **الإيداع**. وموظّفٌ يرى رقماً واحداً كبيراً
    يَعِد العميل بما لا يستطيع — يقبل سحباً ودرجُه فارغ، أو إيداعاً ورصيدُه
    الإلكترونيّ صفر.

    ولذلك لا يوجد في هذه الشاشة «الرصيد» مفرداً. في كلّ بطاقةٍ رقمان
    بلونين، وتحت كلٍّ منهما ما يحدّه.
--}}

<div class="topbar py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <strong>🏦 أميال باي — بوّابة شركات الصرافة</strong>
    {{-- الفرع يُعرَض في الترويسة ولا يُختار في أيّ شاشة: هو هويّة الداخل. --}}
    @if($branchName)
        <span class="badge bg-light text-dark">🏬 {{ $branchName }}</span>
    @endif
    <span class="badge bg-secondary">{{ $roleLabel }}</span>
    <span class="ms-auto small">
        {{ $staffName }}@if($staffUsername) <span class="opacity-75" dir="ltr">({{ $staffUsername }})</span>@endif
    </span>
    <a href="{{ route('agent.logout') }}" class="btn btn-sm btn-outline-light">خروج</a>
</div>

<div class="container-fluid p-3" id="ag" data-testid="agent-portal">

    <div class="row g-3 mb-3" id="ag-totals"></div>
    <div id="ag-alerts"></div>

    {{-- التبويبات تختلف بالدور. الصرّاف لا يرى «الفروع» لأنّ له فرعاً
         واحداً هو فرعه، والمدير لا يرى «الشبّاك» لأنّه لا يقف عليه. --}}
    <ul class="nav nav-tabs mb-3">
        @if($role === 'teller')
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ag-counter" data-testid="ag-tab-counter">💵 الشبّاك</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-report" data-testid="ag-tab-report">📋 تقرير اليوم</button></li>
        @else
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ag-staff" data-testid="ag-tab-staff">👤 الموظّفون</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-shifts" data-testid="ag-tab-shifts">🪟 الشبابيك والورديّات</button></li>
            @if($role !== 'branch_manager')
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-branches" data-testid="ag-tab-branches">🏬 الفروع</button></li>
            @endif
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-till" data-testid="ag-tab-till">🧾 حركة النقد</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-earn" data-testid="ag-tab-earn">📈 العمولات</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-ops" data-testid="ag-tab-ops">📜 سجلّ العمليات</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-settle" data-testid="ag-tab-settle">🤝 التسويات</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ag-report" data-testid="ag-tab-report">📋 تقرير اليوم</button></li>
        @endif
    </ul>

    <div class="tab-content">

        @if($role === 'teller')
            @include('agent-views._counter')
        @else
            @include('agent-views._staff')
        @endif

        {{-- كشف التسوية: مستندٌ واحدٌ يقرؤه الصرّاف والإدارة بنفس الشيفرة. --}}
        @include('agent-views._statement')

        {{-- ما دون هذا للإدارة ومديري الفروع. والصرّاف لا يراه: ليس
             إخفاءَ واجهةٍ بل حدَّ صلاحية — نقاطُ النهاية نفسها تفحص الدور. --}}
        @if($role !== 'teller')
        @if($role === 'head_office')
        {{-- ============ الفروع ============ --}}
        <div class="tab-pane fade" id="ag-branches">
            <div class="card p-3">
                <div class="d-flex mb-3">
                    <h6 class="mb-0">فروعك</h6>
                    <button class="btn btn-sm btn-primary ms-auto" id="ag-new-branch" data-testid="ag-new-branch">+ فرع جديد</button>
                </div>
                <div id="ag-branch-list"></div>

                {{-- كان إنشاء الفرع **ستّ نوافذ `prompt` متتالية**: اسم، رمز،
                     هاتف، كلمة سرّ، مدينة، عنوان. من يخطئ في الثالثة يبدأ من
                     الأولى، ولا يرى ما أدخله، ولا يعرف كم بقي. وعلى الهاتف
                     كلّ نافذةٍ تُغطّي الشاشة. --}}
                <div class="modal fade" id="br-modal" tabindex="-1">
                    <div class="modal-dialog"><div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">فرع جديد</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-2"><label class="form-label">اسم الفرع</label>
                                <input class="form-control" id="br-name" placeholder="فرع المكلا"></div>
                            <div class="mb-2"><label class="form-label">رمز الفرع</label>
                                <input class="form-control" id="br-code" dir="ltr" placeholder="MKL">
                                <div class="form-text">حروفٌ لاتينيّة قصيرة — منه تُبنى رموز موظّفي الفرع (MKL-001).</div></div>
                            <div class="mb-2"><label class="form-label">المدينة (اختياري)</label>
                                <input class="form-control" id="br-city"></div>
                            <div class="mb-2"><label class="form-label">العنوان (اختياري)</label>
                                <input class="form-control" id="br-address"></div>
                            <hr>
                            <div class="mb-2"><label class="form-label">هاتف الفرع</label>
                                <input class="form-control" id="br-phone" dir="ltr" placeholder="9677xxxxxxxx"></div>
                            <div class="mb-2"><label class="form-label">كلمة سرّ الفرع (٨ أحرف فأكثر)</label>
                                <input class="form-control" id="br-pass" dir="ltr"></div>
                            <div class="alert alert-info small py-2 mb-0">
                                هذا حسابُ دخولٍ للفرع نفسه. وموظّفوه يُعيَّنون بعد ذلك من تبويب «الموظّفون»،
                                ولكلٍّ منهم رمزُه وكلمةُ سرّه.
                            </div>
                            <div class="text-danger small mt-2" id="br-err"></div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-primary" id="br-save" data-testid="br-save">إنشاء الفرع</button>
                        </div>
                    </div></div>
                </div>
            </div>
        </div>

        @endif
        {{-- ============ حركة النقد ============ --}}
        <div class="tab-pane fade" id="ag-till">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <select id="ag-till-branch" class="form-select" style="max-width:260px"></select>
                    <button class="btn btn-outline-primary" id="ag-till-load">عرض</button>
                    <button class="btn btn-outline-primary ms-auto" id="ag-fund">💳 شحن رصيد إلكترونيّ</button>
                    <button class="btn btn-outline-primary" id="ag-collect">↩️ سحب رصيد من الفرع</button>
                    <button class="btn btn-outline-success" id="ag-cash-in">💵 توريد نقد إلى الفرع</button>
                    <button class="btn btn-outline-secondary" id="ag-cash-out">توريد نقد من الفرع</button>
                    <button class="btn btn-outline-dark" id="ag-count">جرد الدرج</button>
                </div>
                <div id="ag-till-summary" class="row g-3 mb-3"></div>
                <div id="ag-till-moves"></div>
            </div>
        </div>

        {{-- ============ العمولات ============ --}}
        <div class="tab-pane fade" id="ag-earn">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                    <select id="ag-earn-branch" class="form-select" style="max-width:260px"></select>
                    <div><label class="form-label small mb-1">من</label>
                        <input type="date" id="ag-earn-from" class="form-control form-control-sm"></div>
                    <div><label class="form-label small mb-1">إلى</label>
                        <input type="date" id="ag-earn-to" class="form-control form-control-sm"></div>
                    <button class="btn btn-primary btn-sm" id="ag-earn-load">عرض</button>
                </div>
                <div class="alert alert-secondary py-2 small">
                    العمولة تُشتقّ من <strong>قيود الدفتر</strong> لا من عدّادٍ تراكميّ —
                    فالقيود تُلحَق ولا تُعدَّل، والرقم يُعاد حسابه في أيّ وقت ويطابق ما يراه المدقّق.
                </div>
                <div id="ag-earn-summary" class="row g-3 mb-3"></div>
                <div id="ag-earn-days"></div>
            </div>
        </div>

        {{-- ============ التسويات ============ --}}
        <div class="tab-pane fade" id="ag-settle">
            <div class="card p-3">
                {{-- **الاتّجاه المعاكس للشحن.** الوكيل الذي يخدم سحوبات
                     العملاء يمتلئ رصيدُه ويفرغ درجُه. وبلا زرٍّ هنا يقف
                     عاجزاً: رصيدٌ لا يُصرَف ونقدٌ لا يكفي. --}}
                <div class="alert alert-light border d-flex flex-wrap align-items-center gap-3 mb-3">
                    <div>
                        <div class="fw-bold">💵 طلب صرف رصيد نقداً</div>
                        <div class="small text-muted">
                            تُعيد رصيداً إلكترونيّاً وتستلم مالاً حقيقياً من أميال.
                            يُحجز المبلغ من رصيدك حتى تبتّ الإدارة.
                        </div>
                    </div>
                    <button class="btn btn-warning ms-auto" id="ag-payout" data-testid="ag-payout">
                        طلب صرف
                    </button>
                </div>

                <div class="alert alert-secondary py-2 small">
                    <strong>العمولة المستحقّة غير الرصيد الإلكترونيّ:</strong>
                    ذاك سيولةُ تشغيلٍ تتحرّك مع كلّ عملية، وهذه أرباحٌ تُسحب.
                    وخلطُهما يجعل وكيلاً يسحب أرباحه فيعجز عن الإيداع.
                </div>
                <div class="d-flex mb-2"><button class="btn btn-outline-primary btn-sm ms-auto" id="ag-settle-load">تحديث</button></div>
                <div id="ag-settle-summary" class="row g-3 mb-3"></div>
                <div id="ag-settle-list"></div>
            </div>

            {{-- ============ الطبقة الثانية: الشركة وفروعها ============
                 للتمويل طبقتان:

                     أميال ──► الشركة ──► الفرع

                 وما فوق هذا السطر هو الطبقة الأولى. وبلا الثانية يعرف
                 المدير كم اشترى ولا يعرف أين ذهب. --}}
            <div class="card p-3 mt-3">
                <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                    <div>
                        <div class="fw-bold">🏬 تسوية الفروع</div>
                        <div class="small text-muted">
                            ما أعطيتَه كلَّ فرعٍ وما استرددتَه منه — محسوباً من الدفتر لا من الرصيد الحاليّ.
                        </div>
                    </div>
                    <button class="btn btn-outline-primary btn-sm ms-auto" id="ag-bsettle-load">تحديث</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th>الفرع</th><th>سُلِّم إليه</th><th>استُردَّ منه</th><th>الصافي لديه</th>
                            <th>رصيده الإلكترونيّ</th><th>نقدُه</th><th></th>
                        </tr></thead>
                        <tbody id="ag-bsettle-tbody">
                            <tr><td colspan="7" class="text-center text-muted py-3">اضغط «تحديث»</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @endif
        {{-- ============ تقرير اليوم ============ --}}
        <div class="tab-pane fade" id="ag-report">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                    {{-- الصرّاف له فرعٌ واحد: تُملأ القائمة به وتُخفى.
                         سؤالُه «أيّ فرع؟» وله فرعٌ واحدٌ نقرةٌ بلا معنى. --}}
                    <select id="ag-rep-branch" class="form-select" style="max-width:260px"
                            @if($role === 'teller') hidden @endif></select>
                    <div><label class="form-label small mb-1">التاريخ</label>
                        <input type="date" id="ag-rep-date" class="form-control form-control-sm"></div>
                    <button class="btn btn-primary btn-sm" id="ag-rep-load">عرض</button>
                    <button class="btn btn-outline-secondary btn-sm" id="ag-rep-print">طباعة</button>
                </div>
                <div id="ag-rep-body"></div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('agent') }}';
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const num = n => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 0});

    let branches = [];
    let customer = null;

    async function get(p) { return (await fetch(BASE + p, {headers: {'Accept': 'application/json'}})).json(); }
    async function post(p, b) {
        return (await fetch(BASE + p, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(b || {}),
        })).json();
    }

    // AMIAL-AGENT-STAFF-002 — وصولٌ لا يسقط على عنصرٍ غائب.
    //
    // **العطل الذي أدخل هذا.** صارت التبويبات تختلف بالدور، فاختفت عناصر
    // الشبّاك من صفحة الإدارة. وكان السكربت يصل إلى `#ag-find` مباشرةً،
    // فيُرمى TypeError عند أوّل عنصرٍ غائب **ويتوقّف الملفّ كلّه**: لا
    // مؤشّرات ولا فروع ولا أيّ تبويب. سطرٌ واحدٌ عطّل اللوحة كلّها.
    //
    // والحلّ عنصرٌ صوريّ بدل `null`: الإسناد إليه لا يفعل شيئاً، والقراءة
    // منه تُعيد فراغاً، وربطُ حدثٍ عليه يُهمَل. فيبقى ما يخصّ دور الداخل
    // عاملاً ويُتجاهَل ما لا يخصّه — بدل أن يسقط الاثنان معاً.
    //
    // (وهذا أسلم من إعادة كتابة كلّ إسناد: أوّل محاولةٍ فعلت ذلك بتعبيرٍ
    // نمطيّ جشع فقطع دالّةَ سهمٍ في منتصفها وأنتجت قوساً زائداً — أي عطلاً
    // جديداً مكان القديم.)
    const NOOP_EL = new Proxy({}, {
        get: (t, k) => (k === 'addEventListener' || k === 'removeEventListener'
            || k === 'focus' || k === 'click' || k === 'reset') ? (() => {})
            : (k === 'value' || k === 'textContent' || k === 'innerHTML') ? ''
            : (k === 'classList') ? { add() {}, remove() {}, toggle() {} }
            : (k === 'style') ? {}
            : (k === 'dataset') ? {}
            : undefined,
        set: () => true,
    });
    // `document.getElementById` تُكتب هنا بلا اختصار عمداً: الاستبدال الشامل
    // الذي أنشأ هذه الدالّة كان سيبتلع جسمها أيضاً فتستدعي نفسها بلا نهاية —
    // وهو ما وقع فعلاً وأنتج «Maximum call stack size exceeded».
    const $el = (id) => document.getElementById(id) || NOOP_EL;

    // الرقمان دائماً معاً — انظر شرح أعلى الملفّ.
    const dual = (cash, emoney, title, sub, cls) => `
        <div class="col-lg-3 col-md-6"><div class="card p-3 h-100 ${cls || ''}">
            <div class="small text-muted">${title}</div>
            <div class="d-flex justify-content-between align-items-end mt-1">
                <div><div class="fs-5 fw-bold money cash">${num(cash)}</div>
                     <div class="small text-muted">نقد — يحدّ السحب</div></div>
                <div class="text-end"><div class="fs-5 fw-bold money emoney">${num(emoney)}</div>
                     <div class="small text-muted">رصيد — يحدّ الإيداع</div></div>
            </div>
            ${sub ? `<div class="small text-muted mt-1">${sub}</div>` : ''}
        </div></div>`;

    async function loadOverview() {
        const j = await get('/overview');
        if (!j.success) return;
        const m = j.meta;

        branches = m.branches;

        // ── مؤشّرات الشركة ──────────────────────────────────────────
        //
        // الأرصدة تقول «كم عندنا»، ومؤشّرات اليوم تقول «ماذا حدث». ومديرُ
        // شركةٍ يرى الأرصدة وحدها لا يعرف أنّ فرعاً لم يفتح شبّاكاً اليوم،
        // ولا أنّ ورديّةً أُغلقت بعجز.
        const t = m.today || {};
        const kpi = (title, value, sub, cls) => `
            <div class="col-lg-3 col-md-6"><div class="card p-3 h-100 ${cls || ''}">
                <div class="small text-muted">${title}</div>
                <div class="fs-4 fw-bold money mt-1">${value}</div>
                ${sub ? `<div class="small text-muted mt-1">${sub}</div>` : ''}
            </div></div>`;

        $el('ag-totals').innerHTML =
            dual(m.totals.cash_on_hand, m.totals.emoney, 'إجمالي الفروع',
                 m.totals.branches + ' فرع' +
                 (m.totals.low_cash_branches ? ` • <span class="text-danger">${m.totals.low_cash_branches} نقدها منخفض</span>` : ''),
                 m.totals.low_cash_branches ? 'border-danger' : '')

            + kpi('عمليات اليوم',
                  `${(t.deposits_count || 0) + (t.withdrawals_count || 0)}`,
                  `إيداع ${num(t.deposits_total || 0)} · سحب ${num(t.withdrawals_total || 0)}`)

            + kpi('الشبابيك',
                  `${t.shifts_open_now || 0} <span class="fs-6 text-muted">مفتوح الآن</span>`,
                  `${t.shifts_opened || 0} ورديّة اليوم · في الأدراج ${num(t.drawers_cash || 0)}`,
                  t.branches_idle ? 'border-warning' : '')

            + kpi('الموظّفون',
                  `${t.staff_active || 0}<span class="fs-6 text-muted"> / ${t.staff_total || 0}</span>`,
                  `${t.tellers || 0} صرّاف`
                  + (t.branches_idle
                     ? ` • <span class="text-warning">${t.branches_idle} فرع بلا شبّاك اليوم</span>` : ''));

        // فروق الجرد لا تُقاصّ: فرعٌ نقص خمسةً وآخرُ زاد خمسةً ليسا «صفراً»،
        // هما حادثتان تستحقّان سؤالين. فيُعرَض العدد لا المجموع وحده.
        $el('ag-alerts').innerHTML = t.shifts_with_variance
            ? `<div class="alert alert-warning py-2 mb-3">
                 ⚠️ <strong>${t.shifts_with_variance}</strong> ورديّة أُغلقت بفرقٍ اليوم
                 (صافي الفرق ${num(t.variance_total)}) — راجعها في «الشبابيك والورديّات».
               </div>`
            : '';

        const opts = branches.map(b =>
            `<option value="${b.id}">${esc(b.name)} (${esc(b.code)})</option>`).join('');
        $el('ag-till-branch').innerHTML = opts;
        $el('ag-earn-branch').innerHTML = opts;
        $el('ag-rep-branch').innerHTML = opts;

        $el('ag-branch-list').innerHTML = branches.length ? `
            <div class="table-responsive"><table class="table" data-testid="ag-branch-table">
                <thead class="thead-light"><tr><th>الفرع</th><th>المدينة</th>
                    <th class="text-end">نقد الدرج</th><th class="text-end">الرصيد الإلكترونيّ</th><th>الحالة</th></tr></thead>
                <tbody>${branches.map(b => `
                    <tr class="${b.cash_is_low ? 'table-warning' : ''}">
                        <td><strong>${esc(b.name)}</strong><div class="small text-muted">${esc(b.code)} • ${esc(b.phone)}</div></td>
                        <td>${esc(b.city)}</td>
                        <td class="text-end money cash fw-bold">${num(b.cash_on_hand)}</td>
                        <td class="text-end money emoney fw-bold">${num(b.emoney_balance)}</td>
                        <td>${b.is_active ? '<span class="badge bg-success">يعمل</span>' : '<span class="badge bg-secondary">موقوف</span>'}
                            ${b.cash_is_low ? '<span class="badge bg-warning text-dark">نقد منخفض</span>' : ''}
                            ${b.cash_is_overloaded ? '<span class="badge bg-danger">نقد فوق الحدّ</span>' : ''}</td>
                    </tr>`).join('')}</tbody>
            </table></div>`
            : '<div class="alert alert-secondary">لا فروع بعد — أضف فرعك الأوّل</div>';
    }

    // ---------- الشبّاك ----------
    //
    // **حُذف من هنا.** كان في هذا الموضع شبّاكٌ كاملٌ مبنيٌّ على قائمة
    // «اختر الفرع» (`ag-branch`) ينادي `POST /branches/{id}/deposit`.
    // وقد استُبدل بشبّاك الصرّاف في `_counter.blade.php`: الفرع يأتي من
    // الورديّة لا من قائمةٍ منسدلة، والمسار `POST /counter/deposit`.
    //
    // فبقيت الشيفرة تشير إلى اثني عشر عنصراً لا وجود لها وإلى مسارٍ
    // أُزيل — لا تُلقي خطأً لأنّ `NOOP_EL` يبتلعه، فلا يظهر العطل ولا
    // يُحذف الميت. وهو الصنف الذي يجعل قارئ الشيفرة يظنّ أنّ للوحة
    // شبّاكاً وهي بلا شبّاك.

    // ---------- الفروع ----------
    $el('ag-new-branch').onclick = () => {
        $el('br-err').textContent = '';
        new bootstrap.Modal($el('br-modal')).show();
    };

    $el('br-save').onclick = async () => {
        const err = $el('br-err');
        err.textContent = '';

        const name = $el('br-name').value.trim();
        const code = $el('br-code').value.trim();
        const phone = $el('br-phone').value.trim();
        const password = $el('br-pass').value;

        // يُفحص كلّ شيءٍ **قبل** الإرسال والحقول كلّها أمام عين المستعمل —
        // بخلاف سلسلة `prompt` التي كانت تُسقط كلّ ما أُدخل عند أوّل خطأ.
        if (name.length < 2) { err.textContent = 'اسم الفرع إلزاميّ'; return; }
        if (!code) { err.textContent = 'رمز الفرع إلزاميّ'; return; }
        if (phone.replace(/\D+/g, '').length < 9) { err.textContent = 'هاتف الفرع ناقص'; return; }
        if (password.length < 8) { err.textContent = 'كلمة السرّ ثمانية أحرف فأكثر'; return; }

        $el('br-save').disabled = true;
        try {
            const j = await post('/branches', {
                name, code, phone, password,
                city: $el('br-city').value.trim() || null,
                address: $el('br-address').value.trim() || null,
            });
            bootstrap.Modal.getInstance($el('br-modal')).hide();
            alert(j.message || 'أُنشئ الفرع');
            ['br-name','br-code','br-phone','br-pass','br-city','br-address']
                .forEach(id => { $el(id).value = ''; });
            await loadOverview();
        } catch (e) {
            err.textContent = e.message || 'تعذّر إنشاء الفرع';
        } finally {
            $el('br-save').disabled = false;
        }
    };

    // ---------- حركة النقد ----------
    $el('ag-till-load').onclick = loadTill;

    async function loadTill() {
        const id = $el('ag-till-branch').value;
        if (!id) return;
        const j = await get(`/branches/${id}/till`);
        if (!j.success) return;
        const s = j.meta.summary;

        $el('ag-till-summary').innerHTML =
            dual(s.cash_on_hand, s.emoney_balance, 'رصيد الفرع الآن',
                 s.last_counted_at ? 'آخر جرد: ' + esc(String(s.last_counted_at).slice(0, 10)) : 'لم يُجرَد بعد',
                 s.is_low ? 'border-danger' : (s.is_overloaded ? 'border-warning' : '')) +
            `<div class="col-lg-3 col-md-6"><div class="card p-3 h-100">
                <div class="small text-muted">اليوم — إيداعات</div>
                <div class="fs-5 fw-bold money cash">${num(s.today.deposits)}</div></div></div>
             <div class="col-lg-3 col-md-6"><div class="card p-3 h-100">
                <div class="small text-muted">اليوم — سحوبات</div>
                <div class="fs-5 fw-bold money">${num(s.today.withdrawals)}</div></div></div>` +
            (s.is_overloaded ? `<div class="col-12"><div class="alert alert-danger mb-0">
                النقد فوق الحدّ الأعلى — وَرِّد إلى الخزنة. فرعٌ يحتفظ بنقدٍ كبير خطرٌ أمنيّ لا ميزة سيولة.
            </div></div>` : '');

        $el('ag-till-moves').innerHTML = `
            <div class="table-responsive"><table class="table table-sm" data-testid="ag-till-table">
                <thead class="thead-light"><tr><th>الحركة</th><th class="text-end">المبلغ</th>
                    <th class="text-end">الرصيد بعدها</th><th>المرجع</th><th>الموظّف</th><th>التاريخ</th></tr></thead>
                <tbody>${(j.meta.movements || []).map(m => `
                    <tr><td><span class="badge bg-${m.direction === 'in' ? 'success' : 'secondary'}">${m.direction === 'in' ? 'داخل' : 'خارج'}</span>
                        ${esc(m.reason_label)}${m.note ? `<div class="small text-muted">${esc(m.note)}</div>` : ''}</td>
                        <td class="text-end money">${num(m.amount)}</td>
                        <td class="text-end money fw-bold">${num(m.balance_after)}</td>
                        <td class="small font-monospace">${esc(m.reference || '—')}</td>
                        <td class="small">${esc(m.actor)}</td>
                        <td class="small">${esc(String(m.at || '').slice(0, 16).replace('T', ' '))}</td></tr>`).join('')
                    || '<tr><td colspan="6" class="text-muted text-center py-3">لا حركة</td></tr>'}</tbody>
            </table></div>`;
    }

    async function cashMove(dir) {
        const id = $el('ag-till-branch').value;
        if (!id) return;
        const amount = prompt(dir === 'in' ? 'مبلغ التوريد إلى الفرع:' : 'مبلغ التوريد من الفرع:');
        if (!amount) return;
        const note = prompt('السبب (٥ أحرف على الأقل):');
        if (!note || note.length < 5) { alert('السبب إلزامي'); return; }

        const j = await post(`/branches/${id}/cash`, {direction: dir, amount, note});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) { loadTill(); loadOverview(); }
    }

    // شحن الرصيد **غير** توريد النقد: هذا يمكّن الفرع من **الإيداع**، وذاك
    // يمكّنه من **السحب**. وخلطُهما يجعل مديراً يورّد نقداً ويظنّ أنّه مكّن
    // فرعه من قبول الإيداعات.
    $el('ag-fund').onclick = async () => {
        const id = $el('ag-till-branch').value;
        if (!id) return;
        const amount = prompt('مبلغ الشحن (رصيد إلكترونيّ — يمكّن الفرع من قبول الإيداعات):');
        if (!amount) return;
        const note = prompt('السبب (٥ أحرف على الأقل):');
        if (!note || note.length < 5) { alert('السبب إلزامي'); return; }

        const j = await post(`/branches/${id}/fund`, {amount, note});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) { loadTill(); loadOverview(); loadBranchSettlement(); }
    };

    // الاتّجاه المعاكس. فرعٌ يخدم السحوبات يمتلئ رصيدُه ويفرغ درجُه، وبلا
    // هذا يبقى الرصيد حبيساً عنده: لا يُوزَّع على فرعٍ يحتاجه، ولا يُعاد إلى
    // أميال — لأنّ طلب الصرف يُقدَّم من محفظة الشركة وحدها.
    $el('ag-collect').onclick = async () => {
        const id = $el('ag-till-branch').value;
        if (!id) return;
        const amount = prompt('مبلغ السحب من الفرع (رصيد إلكترونيّ يعود إلى الشركة):');
        if (!amount) return;
        const note = prompt('السبب (٥ أحرف على الأقل):');
        if (!note || note.length < 5) { alert('السبب إلزامي'); return; }

        const j = await post(`/branches/${id}/collect`, {amount, note});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) { loadTill(); loadOverview(); loadBranchSettlement(); }
    };

    // تسوية الفروع — الطبقة الثانية.
    async function loadBranchSettlement() {
        const tb = document.getElementById('ag-bsettle-tbody');
        if (!tb) return;   // الدور لا يملك التبويب أصلاً
        tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">جارٍ التحميل…</td></tr>';
        const j = await get('/branch-settlement');
        const rows = (j.data && j.data.branches) || [];
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">لا فروع بعد</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(b => `
            <tr>
                <td><b>${esc(b.name)}</b> <span class="small text-muted">${esc(b.code)}</span></td>
                <td>${num(b.float_given)}</td>
                <td>${num(b.float_returned)}</td>
                <td class="fw-bold">${num(b.float_net)}</td>
                <td>${num(b.emoney_balance)}</td>
                <td>${num(b.cash_on_hand)}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" data-bfund="${b.id}">شحن</button>
                    <button class="btn btn-sm btn-outline-secondary" data-bcollect="${b.id}">سحب</button>
                </td>
            </tr>`).join('');
    }

    $el('ag-bsettle-tbody').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-bfund], button[data-bcollect]');
        if (!btn) return;
        const isFund = btn.hasAttribute('data-bfund');
        const id = isFund ? btn.dataset.bfund : btn.dataset.bcollect;
        const amount = prompt(isFund ? 'مبلغ الشحن للفرع:' : 'مبلغ السحب من الفرع:');
        if (!amount) return;
        const note = prompt('السبب (٥ أحرف على الأقل):');
        if (!note || note.length < 5) { alert('السبب إلزامي'); return; }
        const j = await post(`/branches/${id}/${isFund ? 'fund' : 'collect'}`, {amount, note});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) { loadBranchSettlement(); loadOverview(); }
    });

    $el('ag-bsettle-load').addEventListener('click', loadBranchSettlement);

    $el('ag-cash-in').onclick = () => cashMove('in');
    $el('ag-cash-out').onclick = () => cashMove('out');

    $el('ag-count').onclick = async () => {
        const id = $el('ag-till-branch').value;
        if (!id) return;
        const counted = prompt('المبلغ المعدود فعلاً في الدرج:');
        if (counted === null) return;
        const note = prompt('ملاحظة الجرد (١٠ أحرف على الأقل — تفسير الفرق هو الفائدة كلّها):');
        if (!note || note.length < 10) { alert('الملاحظة إلزامية'); return; }

        const j = await post(`/branches/${id}/count`, {counted_amount: counted, note});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) { loadTill(); loadOverview(); }
    };

    // ---------- العمولات ----------
    $el('ag-earn-load').onclick = loadEarnings;

    async function loadEarnings() {
        const id = $el('ag-earn-branch').value;
        if (!id) return;
        const from = $el('ag-earn-from').value;
        const to = $el('ag-earn-to').value;
        const qs = [];
        if (from) qs.push('from=' + from);
        if (to) qs.push('to=' + to);

        const j = await get(`/branches/${id}/commissions` + (qs.length ? '?' + qs.join('&') : ''));
        if (!j.success) return;
        const m = j.meta;

        const tile = (t, v, s2, cls) => `
            <div class="col-lg-3 col-6"><div class="card p-3 h-100 ${cls || ''}">
                <div class="small text-muted">${t}</div><div class="fs-5 fw-bold money">${num(v)}</div>
                ${s2 ? `<div class="small text-muted">${s2}</div>` : ''}</div></div>`;

        $el('ag-earn-summary').innerHTML =
            tile('عمولتك', m.total_commission, m.operations + ' عملية', 'border-success') +
            tile('إجمالي الرسوم', m.total_fee, 'ما دفعه العملاء') +
            // ما يذهب للمنصّة يُعرَض صراحةً: وكيلٌ يرى ما كسبه ولا يرى ما
            // دفعه يظنّ الرسم كلّه له، فيحتجّ يوم التسوية.
            tile('حصّة المنصّة', m.platform_share, 'من الرسوم') +
            tile('عدد العمليات', m.operations, m.from + ' → ' + m.to);

        $el('ag-earn-days').innerHTML = `
            <div class="table-responsive"><table class="table table-sm" data-testid="ag-earn-table">
                <thead class="thead-light"><tr><th>اليوم</th><th>إيداعات</th><th>سحوبات</th>
                    <th class="text-end">الحجم</th><th class="text-end">الرسوم</th><th class="text-end">عمولتك</th></tr></thead>
                <tbody>${(m.days || []).map(d => `
                    <tr><td>${esc(d.date)}</td><td>${d.deposits}</td><td>${d.withdrawals}</td>
                        <td class="text-end money">${num(d.volume)}</td>
                        <td class="text-end money">${num(d.fee)}</td>
                        <td class="text-end money fw-bold cash">${num(d.commission)}</td></tr>`).join('')
                    || '<tr><td colspan="6" class="text-muted text-center py-3">لا عمليات في هذه المدّة</td></tr>'}</tbody>
            </table></div>`;
    }

    // ---------- التسويات ----------
    $el('ag-settle-load').onclick = loadSettlements;

    $el('ag-payout').onclick = async () => {
        const amount = prompt('مبلغ الصرف (رصيد إلكترونيّ تُعيده وتستلم مقابله نقداً):');
        if (!amount) return;
        const note = prompt('ملاحظة (اختياري):') || null;
        try {
            const j = await post('/settlements/payout', {amount, note});
            alert(j.message || 'أُرسل الطلب');
            loadSettlements(); loadOverview();
        } catch (e) { alert(e.message); }
    };

    async function loadSettlements() {
        const j = await get('/settlements');
        if (!j.success) return;
        const m = j.meta;

        $el('ag-settle-summary').innerHTML = `
            <div class="col-lg-4 col-6"><div class="card p-3 ${Number(m.summary.pending) > 0 ? 'border-warning' : ''}">
                <div class="small text-muted">بانتظار التسوية</div>
                <div class="fs-5 fw-bold money">${num(m.summary.pending)}</div>
                <div class="small text-muted">${m.summary.pending_count} طلب</div></div></div>
            <div class="col-lg-4 col-6"><div class="card p-3 border-success">
                <div class="small text-muted">سُوِّي فعلاً</div>
                <div class="fs-5 fw-bold money">${num(m.summary.completed)}</div></div></div>`;

        $el('ag-settle-list').innerHTML = `
            <div class="table-responsive"><table class="table table-sm" data-testid="ag-settle-table">
                <thead class="thead-light"><tr><th>المرجع</th><th>النوع</th><th class="text-end">المبلغ</th>
                    <th class="text-end">العمولة</th><th>الحالة</th><th>الطريقة</th><th>التاريخ</th></tr></thead>
                <tbody>${(m.items || []).map(s2 => `
                    <tr><td class="font-monospace small">${esc(String(s2.ulid).slice(0, 12))}…</td>
                        <td>${esc(s2.type)}</td>
                        <td class="text-end money">${num(s2.amount)}</td>
                        <td class="text-end money cash">${num(s2.commission)}</td>
                        <td><span class="badge bg-${s2.status === 'completed' ? 'success' : (s2.status === 'pending' ? 'warning text-dark' : 'secondary')}">${esc(s2.status)}</span></td>
                        <td class="small">${esc(s2.method)}</td>
                        <td class="small">${esc(String(s2.created_at).slice(0, 10))}</td></tr>`).join('')
                    || '<tr><td colspan="7" class="text-muted text-center py-3">لا تسويات</td></tr>'}</tbody>
            </table></div>`;
    }

    // ---------- تقرير اليوم ----------
    $el('ag-rep-load').onclick = loadReport;
    $el('ag-rep-print').onclick = () => window.print();

    async function loadReport() {
        const id = $el('ag-rep-branch').value;
        if (!id) return;
        const date = $el('ag-rep-date').value;
        const j = await get(`/branches/${id}/report` + (date ? '?date=' + date : ''));
        if (!j.success) return;
        const m = j.meta, c = m.cash;

        const row = (label, val, cls) =>
            `<tr class="${cls || ''}"><td>${label}</td><td class="text-end money fw-bold">${num(val)}</td></tr>`;

        $el('ag-rep-body').innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div><h5 class="mb-0">${esc(m.branch.name)}</h5>
                     <div class="small text-muted">${esc(m.branch.code)} — ${esc(m.date)}</div></div>
                <div class="text-end">
                    ${c.reconciles
                        ? '<span class="badge bg-success fs-6">الحركة مطابقة ✓</span>'
                        : `<span class="badge bg-danger fs-6">فرق ${num(Number(c.expected) - Number(c.closing))}</span>`}
                    ${m.counted_today ? '' : '<div class="small text-danger mt-1">⚠️ لم يُجرَد الدرج اليوم</div>'}
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6"><div class="card p-3">
                    <h6>حركة النقد</h6>
                    <table class="table table-sm mb-0">
                        ${row('الرصيد الافتتاحيّ', c.opening)}
                        ${row('+ إيداعات العملاء', c.deposits)}
                        ${row('− سحوبات العملاء', c.withdrawals)}
                        ${row('+ توريد إلى الفرع', c.treasury_in)}
                        ${row('− توريد من الفرع', c.treasury_out)}
                        ${Number(c.adjustments) !== 0 ? row('± تسويات جرد', c.adjustments, 'table-warning') : ''}
                        ${row('= الرصيد المتوقَّع', c.expected, 'table-light')}
                        ${row('الرصيد المسجَّل', c.closing, c.reconciles ? 'table-success' : 'table-danger')}
                    </table>
                </div></div>

                <div class="col-lg-6"><div class="card p-3">
                    <h6>الملخّص</h6>
                    <table class="table table-sm mb-0">
                        <tr><td>عدد الإيداعات</td><td class="text-end fw-bold">${m.counts.deposits}</td></tr>
                        <tr><td>عدد السحوبات</td><td class="text-end fw-bold">${m.counts.withdrawals}</td></tr>
                        ${row('عمولة اليوم', m.commission)}
                        ${row('الرصيد الإلكترونيّ', m.emoney_balance)}
                    </table>
                    <div class="small text-muted mt-2">
                        الرصيد المتوقَّع محسوبٌ من الافتتاحيّ والحركة — لا مقروءٌ من الخزنة.
                        وقراءتُه منها تجعل المطابقة تقارن الرقم بنفسه.
                    </div>
                </div></div>
            </div>`;
    }

    loadOverview();
})();
</script>
</body>
</html>
