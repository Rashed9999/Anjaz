@extends('layouts.admin.app')
@section('title', 'مساحة عمل المنصّة')
@section('content')
@php
    /**
     * AMIAL-WORKSPACE-002 — **مساحةُ العمل تقول ما ينتظر، لا ما هو متاح.**
     *
     * كانت فهرسَ روابطَ لا أكثر: عشرةُ تبويباتٍ وخمسون بطاقةً، كلُّها تقول
     * «هذا موجود» ولا واحدةٌ تقول «اثنا عشرَ طلبَ هويّةٍ تنتظر منذ الصباح».
     * فصار للصفحة رأسٌ يقول ما ينتظر، ثمّ مجالاتُ العمل، ثمّ كلُّ الخدمات.
     *
     * **ولا رقمَ مكتوبٌ في هذا القالب** — كلُّها من
     * `OperatorWorkspaceService` (‏`amial-admin-command`: «Never hardcode
     * financial or operational KPIs»). وما لا يُصرَّح به يقول «غير مصرَّح»
     * لا «٠».
     *
     * **وكلُّ روابط اللوحة تبقى هنا**: هذه الصفحةُ هي مدخلُها الوحيد بعد
     * نقل التفاصيل من الشريط الجانبيّ — فطيُّ خدمةٍ منها يقطع بابَها.
     */
    $can = $wsCan;
    $tabs = [
        ['id'=>'overview','title'=>'نظرة عامة','icon'=>'📊','desc'=>'لوحة القيادة والمؤشّرات التنفيذيّة.','items'=>[
            ['لوحة القيادة التنفيذية', route('admin.amial.executive.index'), 'platform.audit.view'],
        ]],
        ['id'=>'customers','title'=>'العملاء والحسابات','icon'=>'👥','desc'=>'إدارة العملاء والحسابات والدعم والاستعادة.','items'=>[
            ['مركز العملاء', route('admin.amial.customer.page'), 'platform.customers.view'], ['إنشاء العملاء', route('admin.amial.hub.customers'), 'platform.customers.view'], ['مركز الدعم', route('admin.support-center.index'), 'platform.tickets.view'], ['لوحة التحقق: الحسابات الجديدة', route('admin.amial.hub.verification'), 'platform.approvals.decide'], ['استعادة الحسابات', route('admin.amial.recovery.index'), 'platform.approvals.decide'], ['بوابات OTP', route('admin.amial.otp.page'), 'platform.settings.update'],
        ]],
        ['id'=>'merchants','title'=>'التجّار','icon'=>'🏪','desc'=>'إدارة التجار، العقود، نقاط البيع، والتسويات.','items'=>[
            ['مركز التجّار', route('admin.amial.hub.merchants'), 'platform.merchants.compliance'], ['الاشتراكات والباقات', route('admin.amial.hub.subscriptions'), 'platform.settings.manage'], ['القدرات والاستحقاقات', route('admin.amial.entitlements.page'), 'platform.settings.manage'], ['فواتير التجار', route('admin.amial.invoices.page'), 'platform.money.view'], ['كتالوج المنتجات', route('admin.amial.catalog.page'), 'platform.settings.update'], ['موظفو التجّار ونقاط البيع', route('admin.amial.hub.staff'), 'platform.merchants.compliance'], ['رقابة محطات الوقود', route('admin.amial.fuel.page'), 'platform.audit.view'], ['رقابة التجزئة', route('admin.amial.retail.page'), 'platform.audit.view'],
        ]],
        ['id'=>'agents','title'=>'الوكلاء','icon'=>'🤝','desc'=>'إدارة الوكلاء والهيكل التنظيمي والعمولات.','items'=>[
            ['مركز الوكلاء', route('admin.amial.hub.agents'), 'platform.customers.view'], ['تسويات الوكلاء', route('admin.amial.hub.settlements'), 'platform.money.view'], ['بوابة الوكيل', route('agent.login'), null, '_blank'],
        ]],
        ['id'=>'finance','title'=>'المالية والدفتر','icon'=>'📚','desc'=>'المحاسبة، القيود، التقارير المالية، والتسويات.','items'=>[
            ['المركز المالي', route('admin.amial.hub.finance'), 'platform.money.view'], ['مركز الدفتر', route('admin.amial.ledger.page'), 'platform.audit.view'], ['كشف المعاملات', route('admin.transaction.index'), 'platform.transactions.view'], ['تسويات الشركاء', route('admin.amial.partner-settlements.page'), 'platform.money.view'], ['رصيد المنصّة', route('admin.emoney.index'), 'platform.money.view'], ['مصاريف المنصّة', route('admin.expense.index'), 'platform.money.view'], ['الرسوم والأرباح', route('admin.amial.fees.index'), 'platform.fees.view'], ['طلبات السحب', route('admin.withdraw.index'), 'platform.audit.view'],
        ]],
        ['id'=>'compliance','title'=>'الامتثال والمخاطر','icon'=>'🛡️','desc'=>'المراقبة، فحص المعاملات، تقييم المخاطر والضوابط.','items'=>[
            ['مراجعة الهوية', route('admin.amial.kyc.page'), 'platform.customers.kyc.view'], ['طلبات تحديث بيانات العملاء', route('admin.amial.kyc.changes.page'), 'platform.customers.freeze'], ['ملفات فتح الحسابات', route('admin.amial.registration-dossiers.page'), 'platform.registrations.view'], ['مكافحة غسل الأموال', route('admin.amial.aml.page'), 'platform.audit.view'], ['سجل التدقيق', route('admin.amial.audit.index'), 'platform.audit.view'], ['الإشراف والأمن', route('admin.amial.supervision.index'), 'platform.audit.view'], ['أحداث الأمان', route('admin.amial.security-events.index'), 'platform.audit.view'], ['حارس الأمان', route('admin.amial.sentinel.index'), 'platform.audit.view'], ['صحة النظام', route('admin.amial.system.health'), 'platform.audit.view'], ['ساهر — رادار النظام', route('admin.amial.saher.index'), 'saher.view'],
        ]],
        ['id'=>'services','title'=>'خدمات المنصّة','icon'=>'🧩','desc'=>'إدارة الخدمات، التكاملات، وواجهات البرمجة.','items'=>[
            ['النزاعات والدفع الآمن', route('admin.amial.hub.disputes'), 'platform.transactions.view'], ['الجمعيات والتبرعات', route('admin.amial.charity.page'), 'platform.transactions.view'], ['مزوّدو الفواتير', route('admin.amial.surface.bill-providers'), 'platform.settings.update'], ['صناديق العائلة', route('admin.amial.surface.funds'), 'platform.transactions.view'], ['طلبات الأموال', route('admin.amial.surface.payment-requests'), 'platform.transactions.view'],
        ]],
        ['id'=>'content','title'=>'المحتوى والتواصل','icon'=>'📣','desc'=>'البانرات، الإشعارات، الأسئلة الشائعة، واللغات.','items'=>[
            ['البانرات', route('admin.banner.index'), 'platform.settings.update'], ['إشعارات الدفع', route('admin.notification.add-new'), 'platform.settings.update'], ['الأسئلة الشائعة', route('admin.faq.index'), 'platform.settings.update'], ['اللغات', route('admin.business-settings.language.index'), 'platform.settings.update'],
        ]],
        ['id'=>'operations','title'=>'التشغيل والإعدادات','icon'=>'⚙️','desc'=>'إعدادات النظام، القوالب، والعمليات التشغيلية.','items'=>[
            ['إعدادات الأعمال', route('admin.business-settings.business-setup'), 'platform.settings.update'], ['مفاتيح التشغيل', route('admin.amial.hub.settings'), 'platform.settings.update'], ['حدود بوت واتساب', route('admin.amial.whatsapp.limits.page'), 'platform.settings.update'], ['نطاق التشغيل', route('admin.amial.hub.zones.index'), 'platform.zones.view'], ['إعداد Firebase', route('admin.business-settings.fcm-index'), 'platform.settings.update'], ['الشروط القانونية', route('admin.amial.legal.index'), 'platform.ops.view'], ['حالة التشغيل', route('admin.amial.ops.index'), 'platform.ops.view'],
        ]],
        ['id'=>'staff','title'=>'الموظفون والصلاحيات','icon'=>'🔐','desc'=>'إدارة المستخدمين، المجموعات، والصلاحيات.','items'=>[
            ['موظفو المنصة وتبويباتهم', route('admin.amial.ops.roles.index'), 'platform.staff.view'], ['مصفوفة RBAC', route('admin.amial.surface.rbac'), 'platform.settings.update'], ['المصادقة الثنائية لحسابي', route('admin.amial.2fa.page'), null],
        ]],
    ];
    $tabs = array_values(array_filter($tabs, fn($tab) => collect($tab['items'])->contains(fn($item) => $can($item[2]))));

    // «الأكثر استخداماً» — **روابطُ موجودةٌ في التبويبات نفسِها**، لا
    // وجهاتٌ ثالثة. واختصارٌ يقود إلى ما لا يفتحه الموظّف يَعِد ويُخلف،
    // فيُرشَّح بالصلاحيّة كغيره.
    $frequent = array_values(array_filter([
        ['إنشاء عميل جديد', route('admin.amial.hub.customers'), 'platform.customers.view', 'tio-user-add'],
        ['بحث عن معاملة', route('admin.transaction.index'), 'platform.transactions.view', 'tio-search'],
        ['تقارير الامتثال', route('admin.amial.aml.page'), 'platform.audit.view', 'tio-shield-outlined'],
        ['الموافقات المعلّقة', route('admin.amial.ops.index'), 'platform.ops.view', 'tio-checkmark-square'],
        ['تنبيهات النظام', route('admin.amial.system.health'), 'platform.audit.view', 'tio-notifications'],
    ], fn($f) => $can($f[2])));

    $tone = fn(string $t) => match ($t) {
        'danger' => 'var(--amial-danger)',
        'warning' => 'var(--amial-warning)',
        'success' => 'var(--amial-success)',
        'muted' => 'var(--amial-text-muted)',
        default => 'var(--amial-info)',
    };
@endphp

<style>
    .ws-wrap{max-width:1280px}
    .ws-card{background:var(--amial-surface);border:1px solid var(--amial-border);
        border-radius:var(--amial-radius);box-shadow:var(--amial-shadow)}
    .ws-kpi{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;padding:1rem 1.15rem}
    .ws-kpi .num{font-size:var(--amial-text-2xl);font-weight:700;line-height:1.1}
    .ws-kpi .label{font-size:var(--amial-text-sm);color:var(--amial-text-secondary)}
    .ws-kpi-icon{width:42px;height:42px;border-radius:var(--amial-radius-sm);
        display:grid;place-items:center;font-size:1.15rem;flex:0 0 auto}
    .ws-more{display:block;padding:.55rem 1.15rem;border-top:1px solid var(--amial-border);
        font-size:var(--amial-text-xs);color:var(--amial-info);text-decoration:none}
    .ws-more:hover{background:var(--amial-background)}
    .ws-sec-title{font-size:var(--amial-text-lg);font-weight:700;margin:0}
    .ws-domain{display:block;padding:1rem;text-decoration:none;color:inherit;height:100%}
    .ws-domain:hover{background:var(--amial-background)}
    .ws-domain .t{font-weight:700;font-size:var(--amial-text-base);color:var(--amial-text)}
    .ws-domain .d{font-size:var(--amial-text-xs);color:var(--amial-text-secondary);margin-top:.25rem}
    .ws-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-inline-start:.4rem}
    .ws-chip{display:inline-block;padding:.15rem .55rem;border-radius:999px;font-size:var(--amial-text-xs)}
    .ws-quick{display:flex;align-items:center;justify-content:space-between;gap:.5rem;
        padding:.7rem .9rem;text-decoration:none;color:var(--amial-text);font-size:var(--amial-text-sm)}
    .ws-quick:hover{background:var(--amial-background)}
    .ws-hidden{display:none!important}
</style>

<div class="content container-fluid ws-wrap">

    {{-- ── الرأس ─────────────────────────────────────────────────── --}}
    <nav aria-label="breadcrumb" class="mb-1">
        <ol class="breadcrumb mb-0" style="font-size:var(--amial-text-xs)">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">لوحة الإدارة</a></li>
            <li class="breadcrumb-item active" aria-current="page">مساحة العمل</li>
        </ol>
    </nav>
    <h2 class="page-header-title mb-3">مساحة عمل المنصّة</h2>

    {{-- ── البحث ─────────────────────────────────────────────────────
         **يُصفّي ما هو معروضٌ الآن، ولا يعد بما لا يبحث فيه.** لا يبحث
         في المعاملات ولا في العملاء — فحقلٌ يوحي بذلك ويُخرج «لا نتائج»
         يُقرأ «لا وجود لها»، وهو أسوأ من غيابه. (القاعدة السابعة.) --}}
    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
        <div class="flex-grow-1" style="min-width:260px">
            <input type="search" id="ws-search" class="form-control"
                   placeholder="صفِّ الخدمات بالاسم…" autocomplete="off"
                   aria-label="تصفية الخدمات بالاسم">
        </div>
        <button type="button" class="btn btn-outline-primary" id="ws-toggle-all"
                data-shown="0">⊞ عرض كل الخدمات</button>
    </div>

    {{-- ── ما ينتظر قراراً الآن ──────────────────────────────────── --}}
    <div class="row g-3 mb-4">
        @foreach($wsCards as $card)
            <div class="col-6 col-xl-3">
                <div class="ws-card h-100 d-flex flex-column">
                    <div class="ws-kpi flex-grow-1">
                        <div>
                            <div class="label mb-1">{{ $card['title'] }}</div>
                            {{-- ② و③ «غير مصرَّح» و«غير متاح» ليستا صفراً. --}}
                            @if($card['count'] === null)
                                <div class="num" style="font-size:var(--amial-text-lg);color:var(--amial-text-muted)">
                                    {{ $card['note'] }}
                                </div>
                            @else
                                <div class="num" style="color:{{ $tone($card['tone']) }}">{{ $card['count'] }}</div>
                            @endif
                        </div>
                        <div class="ws-kpi-icon"
                             style="background:{{ $tone($card['tone']) }}1A;color:{{ $tone($card['tone']) }}">
                            <i class="{{ $card['icon'] }}"></i>
                        </div>
                    </div>
                    {{-- ④ البطاقةُ تفتح ما تعدّ — ورقمٌ لا يُنقر ليس مؤشّراً. --}}
                    @if($card['url'])
                        <a class="ws-more" href="{{ $card['url'] }}">عرض التفاصيل ›</a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-4">
        {{-- ── يحتاج تدخّلاً الآن ────────────────────────────────── --}}
        <div class="col-12 col-lg-7">
            <div class="ws-card h-100">
                <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
                    <h3 class="ws-sec-title">يحتاج تدخّلاً الآن</h3>
                </div>
                @if($wsQueue === [])
                    <div class="p-4 text-center" style="color:var(--amial-text-muted);font-size:var(--amial-text-sm)">
                        لا شيء ينتظر قراراً فيما تملك صلاحيّته.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size:var(--amial-text-sm)">
                            <thead>
                                <tr>
                                    <th>النوع</th><th>رقم المرجع</th><th>العمر</th>
                                    <th>الحالة</th><th>الإجراء</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($wsQueue as $row)
                                <tr>
                                    <td>{{ $row['type'] }}</td>
                                    <td class="money">{{ $row['ref'] }}</td>
                                    <td>{{ $row['at'] ? \Carbon\Carbon::parse($row['at'])->diffForHumans(null, true) : '—' }}</td>
                                    <td>
                                        <span class="ws-chip"
                                              style="background:{{ $tone($row['tone']) }}1A;color:{{ $tone($row['tone']) }}">
                                            {{ $row['state'] }}
                                        </span>
                                    </td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="{{ $row['url'] }}">فتح</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── صحّة النظام ───────────────────────────────────────── --}}
        <div class="col-12 col-lg-5">
            <div class="ws-card h-100">
                <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
                    <h3 class="ws-sec-title">صحّة النظام</h3>
                </div>
                <div class="p-3">
                    @if($wsHealth['note'])
                        {{-- **وفشلُ الفاحص ليس «سليم»** — وصفحةٌ خضراءُ فوق
                             راصدٍ ميّتٍ أسوأ من حمراء. --}}
                        <div class="alert alert-warning mb-0" style="font-size:var(--amial-text-sm)">
                            {{ $wsHealth['note'] }}
                        </div>
                    @else
                        @foreach($wsHealth['rows'] as $row)
                            @php $c = $row['status'] === 'healthy' ? 'success'
                                : ($row['status'] === 'warning' ? 'warning'
                                : ($row['status'] === 'unknown' ? 'muted' : 'danger')); @endphp
                            <div class="d-flex align-items-center justify-content-between py-2
                                        {{ $loop->last ? '' : 'border-bottom' }}">
                                <span style="font-size:var(--amial-text-sm)">{{ $row['label'] }}</span>
                                <span style="font-size:var(--amial-text-sm);color:{{ $tone($c) }}">
                                    <span class="ws-dot" style="background:{{ $tone($c) }}"></span>
                                    {{ ['healthy'=>'سليم','warning'=>'متباطئ','unknown'=>'لم يُفحص'][$row['status']] ?? 'متعطّل' }}
                                </span>
                            </div>
                        @endforeach
                        <a class="ws-more px-0 mt-2" href="{{ route('admin.amial.system.health') }}">عرض تفاصيل النظام ›</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── مجالات العمل ──────────────────────────────────────────── --}}
    @if($tabs === [])
        <div class="alert alert-warning">لا توجد تبويبات ممنوحة لهذا الحساب. راجع مدير المنصة.</div>
    @else
    <h3 class="ws-sec-title mb-3">مجالات العمل</h3>
    <div class="row g-3 mb-4" id="ws-domains">
        @foreach($tabs as $tab)
            @php $n = collect($tab['items'])->filter(fn($i) => $can($i[2]))->count(); @endphp
            <div class="col-12 col-md-6 col-xl-3 ws-domain-col" data-name="{{ $tab['title'] }}">
                <div class="ws-card h-100">
                    <a class="ws-domain" href="#workspace-{{ $tab['id'] }}" data-ws-domain="{{ $tab['id'] }}">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div>
                                <div class="t">{{ $tab['icon'] }} {{ $tab['title'] }}</div>
                                <div class="d">{{ $tab['desc'] }}</div>
                            </div>
                            <span style="color:var(--amial-text-muted)">›</span>
                        </div>
                        <div class="d mt-2">{{ $n }} خدمة</div>
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── كلّ الخدمات — **لا تُطوى بل تُخفى**.
         هذه الصفحةُ هي المدخلُ الوحيد للوحة بعد نقل التفاصيل من الشريط
         الجانبيّ، **فحذفُ خدمةٍ منها يقطع بابَها**. فتبقى كلُّها هنا،
         وتُفتَح بزرّ «عرض كل الخدمات» أو بضغط بطاقة مجالٍ. --}}
    <div id="ws-all" class="ws-hidden">
        <h3 class="ws-sec-title mb-3">كلّ الخدمات</h3>
        <ul class="nav nav-pills gap-2 mb-3 flex-wrap" role="tablist">
            @foreach($tabs as $index => $tab)
                <li class="nav-item">
                    <button class="nav-link {{ $index === 0 ? 'active' : '' }}"
                            data-bs-toggle="tab" data-bs-target="#workspace-{{ $tab['id'] }}"
                            id="ws-pill-{{ $tab['id'] }}" type="button">
                        {{ $tab['icon'] }} {{ $tab['title'] }}
                    </button>
                </li>
            @endforeach
        </ul>
        <div class="mb-4">
        <div class="tab-content">
            @foreach($tabs as $index => $tab)
                <div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="workspace-{{ $tab['id'] }}">
                    <div class="row g-3">
                        @foreach($tab['items'] as $item)
                            @continue(! $can($item[2]))
                            @php $blank = ($item[3] ?? null) === '_blank'; @endphp
                            <div class="col-md-6 col-xl-4 ws-item" data-name="{{ $item[0] }}">
                                {{-- AMIAL-WORKSPACE-BLADE-001 — **الشرطُ يُحسَب قبل الوسم.**

                                     كان مكتوباً داخل الوسم:
                                       @if(($item[3] ?? null) === '_blank') …

                                     ومحلّلُ Blade يقف عند أوّل قوسٍ متوازن، فيقرأ
                                     `(($item[3] ?? null)` شرطاً ويترك `=== '_blank')`
                                     نصّاً خارجه — فينكسر القالبُ بـ«unexpected endif»،
                                     **والنتيجةُ 500 على مساحة العمل كلِّها**. --}}
                                <a class="card h-100 text-decoration-none" href="{{ $item[1] }}"
                                   @if($blank) target="_blank" rel="noopener" @endif>
                                    <div class="card-body d-flex align-items-center justify-content-between">
                                        <strong>{{ $item[0] }}</strong><span>←</span>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        </div>
    </div>
    @endif

    {{-- ── الأكثر استخداماً ──────────────────────────────────────── --}}
    @if($frequent !== [])
    <h3 class="ws-sec-title mb-3">الأكثر استخداماً</h3>
    {{-- **سطحٌ مستقلٌّ لا تكرار.** كلُّ وجهةٍ هنا لها بيتُها في «كلّ
         الخدمات»، وهذا بابٌ سريعٌ إليه — وهو عينُ ما يقوله حارسُ التنقّل
         عن الشريط الجانبيّ: «البابُ السريعُ لأكثرِ ما يُفتح ليس تكراراً
         بل تصميم». فيُقاس داخلَه لا معه، ويُوسَم ليُقتطَع. --}}
    <div class="row g-2" id="ws-frequent">
        @foreach($frequent as $f)
            <div class="col-6 col-xl-3">
                <div class="ws-card">
                    <a class="ws-quick" href="{{ $f[1] }}">
                        <span><i class="{{ $f[3] }}"></i> {{ $f[0] }}</span><span>›</span>
                    </a>
                </div>
            </div>
        @endforeach
    </div>
    @endif
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    // **المعرّفاتُ تُقرأ ويُفحَص وجودُها.** سطرٌ يقرأ `null.value` يموت
    // بصمت، فتُضغط الأزرارُ ولا يحدث شيء ولا رسالة — وهو ما عطّل زرّ
    // «إيداع» في الشبّاك. (القاعدة التاسعة، ويمسكه `scripts/dom-refs.py`.)
    var all = document.getElementById('ws-all');
    var toggle = document.getElementById('ws-toggle-all');
    var search = document.getElementById('ws-search');
    if (!all || !toggle) return;

    function show(on) {
        all.classList.toggle('ws-hidden', !on);
        toggle.dataset.shown = on ? '1' : '0';
        toggle.textContent = on ? '⊟ إخفاء كل الخدمات' : '⊞ عرض كل الخدمات';
    }

    toggle.addEventListener('click', function () {
        show(toggle.dataset.shown !== '1');
    });

    document.querySelectorAll('[data-ws-domain]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            show(true);
            var pill = document.getElementById('ws-pill-' + link.dataset.wsDomain);
            if (pill) { pill.click(); pill.scrollIntoView({behavior: 'smooth', block: 'center'}); }
        });
    });

    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim();
            // **وبحثٌ فارغٌ يُعيد كلَّ شيء** — لا يترك الصفحةَ خاويةً.
            if (q === '') {
                document.querySelectorAll('.ws-item,.ws-domain-col')
                    .forEach(function (el) { el.classList.remove('ws-hidden'); });
                return;
            }
            show(true);
            document.querySelectorAll('.ws-item,.ws-domain-col').forEach(function (el) {
                var name = el.dataset.name || '';
                el.classList.toggle('ws-hidden', name.indexOf(q) === -1);
            });
        });
    }
})();
</script>
@endsection
