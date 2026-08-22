@extends('layouts.admin.app')
{{-- AMIAL-DASH-WIDGETS-001 — لوحة القيادة: كلّ رقمٍ يُضغط، وكلّ رقمٍ يسأل عن الدور.

     عطلان أُصلحا هنا، وكلاهما لا يُنتج سطراً في أيّ سجلّ:

     ١) زرّ «المركز المالي» كان يفحص `platform.money.move`، والأرقامُ تحته
        لا تفحص شيئاً. فموظّفُ الدعم يُمنع من فتح المركز المالي ويقرأ
        **خزينة المنصّة وأرباحَها** على الشاشة نفسها. وبابٌ مقفلٌ بجانب
        نافذةٍ مفتوحة ليس حراسة. وهو المبدأ المكتوب في
        `routes/admin/amial.php`: «الصفحة لا الفعلَ وحده».

     ٢) وستُّ بطاقاتٍ ثلاثٌ منها روابط وثلاثٌ `div` صامتة، ورسمٌ لا يُضغط.
        فالمدير يقرأ «حجم السنة» ولا طريق من الرقم إلى ما يفسّره.

     والوجهةُ قِيست لا افتُرضت: `TransactionController` يقبل `date_type`
     بثلاثٍ فقط — `all` و`last_30` و`custom`. **ولا `today` فيها.** فرابطٌ
     إلى `date_type=today` يفتح الصفحة ويردّ 200 ويعرض كلَّ التاريخ. لذلك
     كلُّ مدى هنا `custom` بطرفيه المحسوبين.

     والحرّاس في `tests/Feature/AdminDashboardWidgetsTest.php`. --}}
@section('title', 'لوحة التحكم')

@push('css_or_js')
<style>
    .amdash { --blue:var(--amial-primary); --blue2:var(--amial-primary-light); --gold:var(--amial-yellow); --ink:var(--amial-text); --muted:#8B97A8; }
    .amdash .a-card { background:#fff; border:1px solid #e8edf5; border-radius:18px; box-shadow:0 4px 16px rgba(21,40,76,.035); }
    .amdash a.a-card { transition:.15s; }
    .amdash a.a-card:hover { border-color:#cfd9ea; transform:translateY(-2px); box-shadow:0 6px 18px rgba(5,51,145,.08); }
    .amdash .a-soft { width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px; }
    .amdash .a-kpi-val { font-size:20px;font-weight:800;color:var(--ink);line-height:1.1; }
    .amdash .a-kpi-lbl { font-size:11.5px;color:var(--muted); }
    .amdash .a-hero { background:linear-gradient(135deg,var(--blue),var(--blue2)); border-radius:20px;color:#fff; }
    .amdash .a-hero a { color:#fff;text-decoration:none;display:block; }
    .amdash .a-chip { font-size:11px;padding:3px 10px;border-radius:20px;font-weight:700; }
    .amdash .a-rank { width:26px;height:26px;border-radius:8px;background:#f1f4f9;color:var(--blue);font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center; }
    .amdash .a-bar { width:100%;max-width:24px;border-radius:7px 7px 0 0;transition:.2s; }
    .amdash a.a-col { text-decoration:none; }
    .amdash a.a-col:hover .a-bar { opacity:.75 !important; }
    .amdash .a-denied { background:rgba(255,255,255,.12);border-radius:12px;font-size:11.5px;line-height:1.7; }
    .amdash .a-row-link { text-decoration:none;color:inherit;display:block; }
    .amdash .a-row-link:hover { background:#f7f9fc; }
    .amdash .a-command { background:linear-gradient(180deg,#fff,#f8faff); border:1px solid #e5ebf5; border-radius:16px; }
    .amdash .a-command a { text-decoration:none; color:var(--ink); border:1px solid #e9eef6; background:#fff; border-radius:12px; padding:10px 12px; font-size:12px; font-weight:700; display:flex; align-items:center; gap:7px; }
    .amdash .a-command a:hover { border-color:#aac0e9; color:var(--blue); }
    .amdash .a-source { color:rgba(255,255,255,.72); font-size:10px; line-height:1.5; }
    .amdash .a-attention { min-height:76px; height:100%; display:flex; align-items:center; gap:10px; padding:12px; border:1px solid #e7edf6; border-radius:13px; text-decoration:none; color:var(--ink); background:#fff; }
    .amdash a.a-attention:hover { transform:translateY(-1px); box-shadow:0 5px 16px rgba(21,40,76,.08); }
    .amdash .a-attention-icon { width:34px; height:34px; flex:0 0 34px; display:grid; place-items:center; border-radius:10px; background:#f0f4fb; }
    .amdash .a-attention strong,.amdash .a-attention small { display:block; }
    .amdash .a-attention strong { font-size:14px; line-height:1.35; }
    .amdash .a-attention small { margin-top:2px; color:var(--muted); font-size:10.5px; line-height:1.45; }
    .amdash .a-attention-warning { border-color:#f4d889; }.amdash .a-attention-warning .a-attention-icon { background:#fff6df; }
    .amdash .a-attention-danger { border-color:#f0b7bc; }.amdash .a-attention-danger .a-attention-icon { background:#fff0f1; }
    .amdash .a-attention-success { border-color:#a9d9c1; }.amdash .a-attention-success .a-attention-icon { background:#eaf8ef; }
    .amdash .a-attention-info .a-attention-icon { background:#edf4ff; }
    .amdash .a-attention-muted { background:#f8fafc; border-style:dashed; }
    .amdash .a-fin-map { background:linear-gradient(135deg,#0b2e7d,#0640a6); border-radius:18px; color:#fff; overflow:hidden; }
    .amdash .a-fin-map a { color:#fff; text-decoration:none; }.amdash .a-fin-map a:hover { color:#fff; }
    .amdash .a-fin-node { position:relative; min-height:88px; padding:13px; border:1px solid rgba(255,255,255,.16); border-radius:13px; background:rgba(255,255,255,.08); }
    .amdash .a-fin-node strong,.amdash .a-fin-node small { display:block; }.amdash .a-fin-node strong { font-size:17px; }.amdash .a-fin-node small { font-size:10.5px; color:rgba(255,255,255,.75); line-height:1.45; }
    .amdash .a-fin-arrow { color:rgba(255,255,255,.6); font-size:18px; }
    @media (max-width: 767.98px) {
        .amdash { padding:0 !important; }
        .amdash .a-hero { border-radius:18px; }
        .amdash .a-hero.p-4 { padding:20px !important; }
        .amdash .a-kpi-val { font-size:18px; overflow-wrap:anywhere; }
        .amdash .a-kpi-lbl { font-size:10.5px; line-height:1.45; }
        .amdash .a-card.p-3 { padding:13px !important; }
        .amdash .a-command .d-flex { gap:8px !important; }
        .amdash .a-command a { flex:1 1 calc(50% - 4px); font-size:11px; }
    }
</style>
@endpush

@section('content')
@php
    /* AMIAL-DASH-WIDGETS-001 — سؤالٌ واحدٌ يُسأل مرّةً ويُستعمل في كلّ موضع.
       و`platform.money.move` هو نفسه حارسُ `/hub/finance` — فلا يُخترع
       هنا معيارٌ ثانٍ لنفس البيانات. */
    $viewer = auth('user')->user();
    $canMoney = (bool) $viewer?->hasPlatformPermission('platform.money.move');
    $canTransactions = (bool) $viewer?->hasPlatformPermission('platform.transactions.view');
    $canCustomers = (bool) $viewer?->hasPlatformPermission('platform.customers.view');
    // AMIAL-ADMIN-DOORS-001 — **مجاميعُ المنصّة صلاحيّةٌ مستقلّة.**
    //
    // والقالبُ يحسب صلاحيّاتِه بنفسه، فتصحيحُ المتحكّم وحدَه يترك
    // العناوينَ والبطاقاتِ معروضةً بأصفارٍ — والعنوانُ نفسُه إفشاء:
    // «أعلى العملاء تعاملاً» تقول إنّ ها هنا ترتيباً، ويبقى الرقمُ
    // وحدَه محجوباً.
    $canAnalytics = (bool) $viewer?->hasPlatformPermission('platform.analytics.view');
    $canTickets = (bool) $viewer?->hasPlatformPermission('platform.tickets.manage');
    $canAudit = (bool) $viewer?->hasPlatformPermission('platform.audit.view');

    $year        = (int) now()->year;
    $yearVals    = collect($transaction)->map(fn ($v) => (float) $v);
    $yearTotal   = (float) $yearVals->sum();
    $treasury    = (float) ($balance['unused_balance'] ?? 0);
    $circulating = (float) ($balance['used_balance'] ?? 0);
    $today       = now()->toDateString();

    /* مدىً في كشف المعاملات. و`custom` وحدها يفهمها المتحكّم. */
    $range = fn ($from, $to) => route('admin.transaction.index', [
        'date_type' => 'custom', 'start_date' => $from, 'end_date' => $to,
    ]);
@endphp

<div class="content container-fluid amdash" dir="rtl">

    {{-- ==== الترويسة ==== --}}
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1" style="color:var(--blue)">{{ translate('لوحة تحكم أميال باي') }}</h4>
            <span class="a-chip" style="background:#e7f6ec;color:#12694E">● {{ translate('جميع الأنظمة تعمل') }}</span>
            <small class="text-muted ms-2">{{ now()->format('Y-m-d — H:i') }}</small>
        </div>
        <div class="d-flex gap-2">
            {{-- تحديثٌ يدويّ. والرقمُ المعروض لحظةَ فتح الصفحة يشيخ بلا أن
                 يقول إنّه شاخ — والوقتُ إلى يساره يقول متى قِيس. --}}
            <a href="{{ route('admin.dashboard') }}" class="btn btn-sm btn-outline-secondary" data-testid="btn-refresh">
                ⟳ {{ translate('تحديث') }}
            </a>
            @if ($canTransactions)
                <a href="{{ route('admin.transaction.index') }}" class="btn btn-sm btn-outline-secondary">{{ translate('كشف المعاملات') }}</a>
            @endif
            {{-- AMIAL-OPERATOR-RBAC-003: بطاقاتُ لوحة القيادة بابٌ ثانٍ.
                 وزرٌّ يُعرَض ثمّ يُمنع يجعل المستعمل يظنّ النظام معطَّلاً
                 بدل أن يعرف أنّ الفعل ليس له. --}}
            @if ($canMoney)
                <a href="{{ route('admin.amial.hub.finance') }}" class="btn btn-sm text-white" style="background:var(--blue)" data-testid="btn-finance">
                    {{ translate('المركز المالي') }}
                </a>
            @endif
        </div>
    </div>

    {{-- إجراءاتٌ موجهة لا أزرار زينة: لا يظهر ما لا يملكه الدور. --}}
    <section class="a-command p-3 mb-3" aria-label="إجراءات سريعة">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div>
                <div class="fw-bold" style="font-size:13px;color:var(--ink)">مركز التشغيل</div>
                <small class="text-muted" style="font-size:11px">انتقل مباشرةً إلى المراجعة أو الدعم أو السجل المالي.</small>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if ($canTransactions)<a href="{{ route('admin.transaction.index') }}">↗ كشف العمليات</a>@endif
                @if ($canCustomers)<a href="{{ route('admin.amial.hub.customers') }}">👤 إدارة العملاء</a>@endif
                @if ($canTickets)<a href="{{ route('admin.support-center.index') }}">🎧 مركز الدعم</a>@endif
                @if ($canAudit)<a href="{{ route('admin.amial.ledger.page') }}">📚 ميزان المراجعة</a>@endif
            </div>
        </div>
    </section>

    @php
        $attention = $data['attention'] ?? [];
        $attentionItems = [
            ['key' => 'kyc', 'label' => 'مستندات الهوية بانتظار المراجعة', 'icon' => '🪪', 'href' => route('admin.amial.kyc.page'), 'tone' => 'warning'],
            ['key' => 'tickets', 'label' => 'تذاكر عالية الأولوية مفتوحة', 'icon' => '🎫', 'href' => route('admin.support-center.index', ['tab' => 'tickets']), 'tone' => 'danger'],
            ['key' => 'approvals', 'label' => 'طلبات تنتظر اعتماداً ثانياً', 'icon' => '✅', 'href' => route('admin.support-center.index', ['tab' => 'approvals']), 'tone' => 'warning'],
            ['key' => 'security', 'label' => 'تنبيهات أمان لم تُراجع', 'icon' => '🛡️', 'href' => route('admin.support-center.index', ['tab' => 'insider']), 'tone' => 'danger'],
            ['key' => 'reconciliation', 'label' => 'آخر حالة للمطابقة المالية', 'icon' => '📚', 'href' => route('admin.amial.ledger.page'), 'tone' => 'info'],
        ];
        $visibleAttention = collect($attentionItems)->filter(fn ($item) => ($attention[$item['key']]['state'] ?? 'hidden') !== 'hidden');
    @endphp

    @if ($visibleAttention->isNotEmpty())
        <section class="a-card p-3 mb-3" aria-label="يتطلب انتباهك" data-testid="attention-queue">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <div class="fw-bold" style="color:var(--ink)">يتطلب انتباهك</div>
                    <small class="text-muted">قراءة مباشرة من طوابير التشغيل، وتظهر بحسب صلاحيتك.</small>
                </div>
                <span class="a-chip" style="background:#f0f4fb;color:var(--blue)">تحديث عند فتح الصفحة</span>
            </div>
            <div class="row g-2">
                @foreach ($visibleAttention as $item)
                    @php
                        $entry = $attention[$item['key']] ?? [];
                        $state = $entry['state'] ?? 'unavailable';
                        $count = $entry['count'] ?? null;
                        $isRecon = $item['key'] === 'reconciliation';
                        $tone = $item['tone'];
                        if ($isRecon && ($entry['status'] ?? null) === 'clean') $tone = 'success';
                        if ($isRecon && in_array(($entry['status'] ?? null), ['diverged', 'failed'], true)) $tone = 'danger';
                    @endphp
                    <div class="col-12 col-md-6 col-xl">
                        @if ($state === 'ready')
                            <a class="a-attention a-attention-{{ $tone }}" href="{{ $item['href'] }}" data-attention="{{ $item['key'] }}">
                                <span class="a-attention-icon">{{ $item['icon'] }}</span>
                                <span>
                                    <strong>{{ $isRecon ? (($entry['status'] ?? 'غير معروف') === 'clean' ? 'مطابقة سليمة' : (($entry['status'] ?? 'غير معروف') === 'diverged' ? 'فروقات تحتاج مراجعة' : 'فحص لم يكتمل')) : number_format((int) $count) }}</strong>
                                    <small>{{ $item['label'] }}@if ($isRecon && !empty($entry['ran_at'])) — {{ \Illuminate\Support\Carbon::parse($entry['ran_at'])->format('Y-m-d H:i') }}@endif</small>
                                </span>
                            </a>
                        @else
                            <div class="a-attention a-attention-muted" data-attention="{{ $item['key'] }}" aria-live="polite">
                                <span class="a-attention-icon">{{ $item['icon'] }}</span>
                                <span><strong>{{ $state === 'unknown' ? 'غير معروف' : 'غير متاح' }}</strong><small>{{ $state === 'unknown' ? 'لم تُسجَّل جولة مطابقة بعد؛ ليس هذا دليلاً على السلامة.' : 'مصدر هذه المؤشرات غير مهيأ في هذه البيئة.' }}</small></span>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($canMoney)
        <section class="a-fin-map p-3 p-md-4 mb-3" aria-label="الخريطة المالية التنفيذية" data-testid="financial-map">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <div class="fw-bold">الخريطة المالية التنفيذية</div>
                    <small style="color:rgba(255,255,255,.72)">لقطة تشغيلية قابلة للتتبع، وليست بديلاً عن ميزان المراجعة.</small>
                </div>
                @if ($canAudit)<a href="{{ route('admin.amial.ledger.page') }}" class="a-chip" style="background:rgba(255,255,255,.14)">فتح الدفتر ←</a>@endif
            </div>
            <div class="row g-2 align-items-stretch">
                <div class="col-6 col-lg"><a class="a-fin-node" href="{{ route('admin.amial.hub.finance') }}"><small>تغذية المنصة</small><strong>{{ number_format((float) ($balance['total_balance'] ?? 0), 0) }} ر.ي</strong><small>المصدر: Cash In</small></a></div>
                <div class="col-6 col-lg"><a class="a-fin-node" href="{{ route('admin.amial.hub.finance') }}"><small>أرصدة تشغيلية متداولة</small><strong>{{ number_format($circulating, 0) }} ر.ي</strong><small>المصدر: المحافظ الحالية والمعلّقة</small></a></div>
                <div class="col-6 col-lg"><a class="a-fin-node" href="{{ route('admin.amial.hub.finance') }}"><small>خزينة المنصة</small><strong>{{ number_format($treasury, 0) }} ر.ي</strong><small>المصدر: محفظة المنصة</small></a></div>
                <div class="col-6 col-lg"><a class="a-fin-node" href="{{ route('admin.amial.hub.finance') }}"><small>الرسوم المسجّلة</small><strong>{{ number_format((float) ($balance['total_earned'] ?? 0), 0) }} ر.ي</strong><small>المصدر: رسوم العمليات</small></a></div>
            </div>
        </section>
    @endif

    <div class="row g-3 mb-3">
        {{-- ==== نبض اليوم ==== --}}
        <div class="col-lg-5">
            <div class="a-hero p-4 h-100">
                @if ($canTransactions)
                    <a href="{{ $range($today, $today) }}" data-kpi="today">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div style="opacity:.8;font-size:12px">{{ translate('حجم عمليات اليوم') }}</div>
                                <div class="fw-bold mt-1" style="font-size:30px">
                                    {{ number_format((float) ($data['today']['tx_volume'] ?? 0), 0) }}
                                    <span style="font-size:15px;opacity:.8">ر.ي</span>
                                </div>
                            </div>
                            <span class="a-chip" style="background:rgba(255,255,255,.18)">⚡ {{ number_format($data['today']['tx_count'] ?? 0) }} {{ translate('عملية') }}</span>
                        </div>
                    </a>
                @else
                    <div class="a-denied p-3 text-center" data-testid="transactions-denied">🔒 ملخص العمليات لا يُتاح بصلاحيّتك.</div>
                @endif
                <hr style="border-color:rgba(255,255,255,.2)">

                @if ($canMoney)
                    <div class="row text-center">
                        <div class="col">
                            <a href="{{ route('admin.amial.hub.finance') }}" data-kpi="circulating">
                                <div class="fw-bold" style="font-size:16px">{{ number_format($circulating, 0) }}</div>
                                <small style="opacity:.75;font-size:10.5px">{{ translate('أموال متداولة') }}</small>
                            </a>
                        </div>
                        <div class="col">
                            <a href="{{ route('admin.amial.hub.finance') }}" data-kpi="treasury">
                                <div class="fw-bold" style="font-size:16px">{{ number_format($treasury, 0) }}</div>
                                <small style="opacity:.75;font-size:10.5px">{{ translate('خزينة المنصّة') }}</small>
                            </a>
                        </div>
                        <div class="col">
                            <a href="{{ route('admin.amial.hub.finance') }}" data-kpi="fees">
                                <div class="fw-bold" style="font-size:16px">{{ number_format((float) ($balance['total_earned'] ?? 0), 0) }}</div>
                                <small style="opacity:.75;font-size:10.5px">{{ translate('أرباح الرسوم') }}</small>
                            </a>
                        </div>
                    </div>
                @else
                    {{-- القاعدة السابعة: «غير معروف» ليس صفراً. وصفرٌ مكان
                         رقمٍ محجوب يجعل موظّف الدعم يبلّغ أنّ الخزينة فرغت. --}}
                    <div class="a-denied p-3 text-center" data-testid="money-denied">
                        🔒 {{ translate('أرصدة المنصّة وأرباحها لا تُتاح بصلاحيّتك') }}<br>
                        <span style="opacity:.75">{{ translate('دورك الحاليّ') }}:
                            {{ implode('، ', auth('user')->user()?->platformRoleLabels() ?: [translate('بلا دور')]) }}</span>
                    </div>
                @endif
                <div class="a-source mt-3">@if ($canTransactions) المصدر: صفوف العمليات الأصلية في سجل المعاملات. لا تشمل قيود الاستلام أو الرسوم التابعة. @else الأرقام المحجوبة لا تُستبدل بصفر. @endif</div>
            </div>
        </div>

        {{-- ==== المؤشّرات ==== --}}
        <div class="col-lg-7">
            @php
                $kpis = [];

                if ($canMoney) {
                    $kpis[] = ['k' => 'topup', 'lbl' => 'إجمالي التغذية (Cash In)',
                        'val' => number_format((float) $balance['total_balance'], 0),
                        'ic' => '📥', 'bg' => '#e9f2ff', 'fg' => '#053391',
                        'href' => route('admin.amial.hub.finance')];

                    $kpis[] = ['k' => 'earned', 'lbl' => 'أرباح الرسوم',
                        'val' => number_format((float) $balance['total_earned'], 0),
                        'ic' => '💰', 'bg' => '#fff6e0', 'fg' => '#B8860B',
                        'href' => route('admin.amial.hub.finance')];
                }

                if ($canAnalytics) {
                    $kpis[] = ['k' => 'year', 'lbl' => 'حجم السنة',
                        'val' => number_format($yearTotal, 0),
                        'ic' => '📊', 'bg' => '#eafaf1', 'fg' => '#12694E',
                        'href' => $range($year . '-01-01', $year . '-12-31')];
                }

                if ($canAnalytics) {
                    $kpis[] = ['k' => 'customers', 'lbl' => 'العملاء',
                        'val' => number_format($data['counts']['customers'] ?? 0),
                        'ic' => '👤', 'bg' => '#f0ecff', 'fg' => '#5B2A9E',
                        'href' => route('admin.amial.hub.customers')];

                    $kpis[] = ['k' => 'agents', 'lbl' => 'الوكلاء',
                        'val' => number_format($data['counts']['agents'] ?? 0),
                        'ic' => '🏪', 'bg' => '#fdeef0', 'fg' => '#C0392B',
                        'href' => route('admin.amial.hub.agents')];

                    $kpis[] = ['k' => 'merchants', 'lbl' => 'التجار',
                        'val' => number_format($data['counts']['merchants'] ?? 0),
                        'ic' => '🛒', 'bg' => '#e8f7f6', 'fg' => '#0E7C7B',
                        'href' => route('admin.amial.hub.merchants')];
                }
            @endphp
            <div class="row g-3 h-100">
                @foreach ($kpis as $k)
                    <div class="col-6 col-md-4">
                        {{-- لا `div` بين البطاقات. بطاقةٌ بلا وجهةٍ واجهةٌ
                             زائفة، والقاعدةُ: كلُّ ما يُرى يعمل. --}}
                        <a href="{{ $k['href'] }}" data-kpi="{{ $k['k'] }}"
                           class="a-card p-3 h-100 d-block text-decoration-none">
                            <div class="a-soft mb-2" style="background:{{ $k['bg'] }}">{{ $k['ic'] }}</div>
                            <div class="a-kpi-val">{{ $k['val'] }}</div>
                            <div class="a-kpi-lbl">{{ translate($k['lbl']) }}</div>
                        </a>
                    </div>
                @endforeach
                @if ($kpis === [])
                    <div class="col-12"><div class="a-card p-3 text-muted">لا توجد مؤشرات متاحة لهذا الدور.</div></div>
                @endif
            </div>
        </div>
    </div>

    {{-- ==== الرسم + المتصدّرون ==== --}}
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="a-card p-3 h-100">
                @if ($canAnalytics)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold" style="color:var(--ink)">{{ translate('حجم المعاملات') }} — {{ $year }}</span>
                        <small class="text-muted">{{ translate('الإجمالي') }}: {{ number_format($yearTotal, 0) }} ر.ي</small>
                    </div>
                    @php
                        $maxMonth   = max(1.0, (float) $yearVals->max());
                        $monthNames = ['ينا','فبر','مار','أبر','ماي','يون','يول','أغس','سبت','أكت','نوف','ديس'];
                    @endphp

                    @if ($yearTotal <= 0)
                        <div class="text-center text-muted py-5" data-testid="chart-empty">
                            {{ translate('لا معاملات في') }} {{ $year }} {{ translate('حتى الآن') }}
                        </div>
                    @else
                        <div class="d-flex align-items-end justify-content-between" style="height:210px;gap:6px;border-bottom:1px solid #eef1f5;padding-bottom:6px">
                            @for ($i = 1; $i <= 12; $i++)
                                @php
                                    $v     = (float) ($transaction[$i] ?? 0);
                                    $h     = (int) round(($v / $maxMonth) * 170);
                                    $isMax = $v > 0 && $v >= $maxMonth;
                                    $mFrom = \Illuminate\Support\Carbon::create($year, $i, 1);
                                    $mTo   = $mFrom->copy()->endOfMonth();
                                @endphp
                                <a class="a-col d-flex flex-column align-items-center flex-fill"
                                   data-month="{{ $i }}"
                                   href="{{ $range($mFrom->toDateString(), $mTo->toDateString()) }}"
                                   title="{{ $monthNames[$i-1] }} — {{ number_format($v, 0) }} ر.ي">
                                    <small class="text-muted" style="font-size:9px">{{ $v > 0 ? number_format($v / 1000, 0) . 'k' : '' }}</small>
                                    <div class="a-bar" style="height:{{ max($h, $v > 0 ? 4 : 1) }}px;background:{{ $isMax ? 'var(--gold)' : 'linear-gradient(180deg,var(--amial-primary-light),var(--amial-primary))' }};opacity:{{ $v > 0 ? 1 : .12 }}"></div>
                                    <small class="text-muted mt-1" style="font-size:10px">{{ $monthNames[$i-1] }}</small>
                                </a>
                            @endfor
                        </div>
                    @endif
                @else
                    <div class="text-center text-muted py-5" data-testid="chart-denied">🔒 مخطط العمليات لا يُتاح بصلاحيّتك.</div>
                @endif
            </div>
        </div>

        <div class="col-lg-5">
            @if ($canAnalytics && $canCustomers)
                @php
                    $boards = [
                        ['t' => '🏆 ' . translate('أعلى الوكلاء تعاملاً'),  'rows' => $data['top_agents'] ?? [],    'tid' => 'list-agents'],
                        ['t' => '⭐ ' . translate('أعلى العملاء تعاملاً'), 'rows' => $data['top_customers'] ?? [], 'tid' => 'list-customers'],
                    ];
                @endphp
                @foreach ($boards as $b)
                    <div class="a-card mb-3">
                        <div class="p-3 pb-2 fw-bold" style="color:var(--ink)">{{ $b['t'] }}</div>
                        <div data-testid="{{ $b['tid'] }}">
                            @forelse ($b['rows'] as $idx => $row)
                                @php $uid = $row->user->id ?? null; @endphp
                                <a class="a-row-link d-flex align-items-center justify-content-between px-3 py-2"
                                   style="border-top:1px solid #f3f5f8"
                                   @if ($uid) href="{{ route('admin.amial.hub.account', $uid) }}" @else href="{{ route('admin.amial.hub.customers') }}" @endif>
                                    <span class="d-flex align-items-center gap-2">
                                        <span class="a-rank">{{ $idx + 1 }}</span>
                                        <span>{{ $row->user->f_name ?? '—' }} {{ $row->user->l_name ?? '' }}</span>
                                    </span>
                                    <span class="fw-bold" style="color:var(--blue)">{{ number_format((float) ($row->total_transaction ?? 0), 0) }}</span>
                                </a>
                            @empty
                                <div class="px-3 py-3 text-muted">{{ translate('لا بيانات بعد') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            @else
                <div class="a-card p-4 h-100 text-center text-muted" data-testid="leaders-denied">🔒 القوائم الاسمية للحركة لا تُتاح بصلاحيّتك.</div>
            @endif
        </div>
    </div>

</div>
@endsection
