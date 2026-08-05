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
@section('title', translate('Dashboard'))

@push('css_or_js')
<style>
    .amdash { --blue:#053391; --blue2:#1D4FB8; --gold:#FECA1E; --ink:#1A2433; --muted:#8B97A8; }
    .amdash .a-card { background:#fff; border:1px solid #eef1f5; border-radius:16px; }
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
</style>
@endpush

@section('content')
@php
    /* AMIAL-DASH-WIDGETS-001 — سؤالٌ واحدٌ يُسأل مرّةً ويُستعمل في كلّ موضع.
       و`platform.money.move` هو نفسه حارسُ `/hub/finance` — فلا يُخترع
       هنا معيارٌ ثانٍ لنفس البيانات. */
    $canMoney = (bool) auth('user')->user()?->hasPlatformPermission('platform.money.move');

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
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
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
            <a href="{{ route('admin.transaction.index') }}" class="btn btn-sm btn-outline-secondary">{{ translate('كشف المعاملات') }}</a>
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

    <div class="row g-3 mb-3">
        {{-- ==== نبض اليوم ==== --}}
        <div class="col-lg-5">
            <div class="a-hero p-4 h-100">
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

                /* حجمُ السنة ليس رصيداً — هو حركةٌ يراها كلُّ دورٍ يملك
                   `platform.transactions.view` (والأربعةُ تملكها). فلا
                   يُحجب ما يُفتح كشفُه من القائمة الجانبيّة. */
                $kpis[] = ['k' => 'year', 'lbl' => 'حجم السنة',
                    'val' => number_format($yearTotal, 0),
                    'ic' => '📊', 'bg' => '#eafaf1', 'fg' => '#12694E',
                    'href' => $range($year . '-01-01', $year . '-12-31')];

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
            </div>
        </div>
    </div>

    {{-- ==== الرسم + المتصدّرون ==== --}}
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="a-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold" style="color:var(--ink)">{{ translate('حجم المعاملات') }} — {{ $year }}</span>
                    <small class="text-muted">{{ translate('الإجمالي') }}: {{ number_format($yearTotal, 0) }} ر.ي</small>
                </div>
                @php
                    $maxMonth   = max(1.0, (float) $yearVals->max());
                    $monthNames = ['ينا','فبر','مار','أبر','ماي','يون','يول','أغس','سبت','أكت','نوف','ديس'];
                @endphp

                @if ($yearTotal <= 0)
                    {{-- حالةُ الفراغ تُقال. ورسمٌ بأعمدةٍ صفريّةٍ بلا كلمة
                         يُقرأ «تعطّل القياس» لا «لا حركة بعد». --}}
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
                                /* آخرُ يومٍ يُحسب لا يُكتب — وهو العطل الذي
                                   أسقط ٣١ آذار من كلّ حسابات اللوحة. */
                                $mFrom = \Illuminate\Support\Carbon::create($year, $i, 1);
                                $mTo   = $mFrom->copy()->endOfMonth();
                            @endphp
                            <a class="a-col d-flex flex-column align-items-center flex-fill"
                               data-month="{{ $i }}"
                               href="{{ $range($mFrom->toDateString(), $mTo->toDateString()) }}"
                               title="{{ $monthNames[$i-1] }} — {{ number_format($v, 0) }} ر.ي">
                                <small class="text-muted" style="font-size:9px">{{ $v > 0 ? number_format($v / 1000, 0) . 'k' : '' }}</small>
                                <div class="a-bar" style="height:{{ max($h, $v > 0 ? 4 : 1) }}px;background:{{ $isMax ? 'var(--gold)' : 'linear-gradient(180deg,#1D4FB8,#053391)' }};opacity:{{ $v > 0 ? 1 : .12 }}"></div>
                                <small class="text-muted mt-1" style="font-size:10px">{{ $monthNames[$i-1] }}</small>
                            </a>
                        @endfor
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-5">
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
                            {{-- صفٌّ يقود إلى ملفّ صاحبه. واسمٌ في قائمةٍ لا
                                 يُفتح يترك المدير يبحث عنه يدويّاً في مركزٍ آخر. --}}
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
        </div>
    </div>

</div>
@endsection
