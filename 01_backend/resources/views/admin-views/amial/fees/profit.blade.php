@extends('layouts.admin.app')

@section('title', 'تقرير الأرباح — أميال باي')

@section('content')
{{--
    AMIAL-FEE-TRUTH-011 — **تقريرُ الأرباح: كلُّ رقمٍ يُفسَّر أو يُقال إنّه لا يُفسَّر.**

    ══════════════════════════════════════════════════════════════════════
    كانت «عمولةُ الوكلاء» تُحسب طرحاً: `الإجمالي − الصافي`. أي أنّها
    **مُعرَّفةٌ بأنّها الفرق**، فلا فرقَ يظهر أبداً مهما ضاع ريال: قيدٌ لم
    يُرحَّل، أو رسمٌ حُصّل ولم يُقيَّد — يظهر في خانة الوكلاء وكأنّه دُفع لهم.

    فصار كلُّ طرفٍ يُقاس من مصدره، **والمعادلةُ تُختبر**. وما زاد أو نقص
    يُقال «فرقاً غيرَ مفسَّر» باسمه ورقمه.
--}}
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $maxDaily = collect($report['daily'])->max(fn ($d) => (float) $d['net']) ?: 1;
@endphp

<div class="content container-fluid">

    @include('admin-views.amial.fees._shell')

    {{-- ══ المرشِّحات ══ --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('admin.amial.fees.profit') }}" class="fee-filters">
                <div>
                    <label class="form-label small mb-1" for="p-from">من</label>
                    <input type="date" class="form-control" id="p-from" name="from"
                           value="{{ $from->toDateString() }}" max="{{ now()->toDateString() }}">
                </div>

                <div>
                    <label class="form-label small mb-1" for="p-to">إلى</label>
                    <input type="date" class="form-control" id="p-to" name="to"
                           value="{{ $to->toDateString() }}" max="{{ now()->toDateString() }}">
                </div>

                <div class="f-actions d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">
                        <i class="tio-filter-list"></i> تطبيق
                    </button>
                    <a class="btn btn-outline-secondary"
                       href="{{ route('admin.amial.fees.profit', ['from' => now()->toDateString(), 'to' => now()->toDateString()]) }}">اليوم</a>
                    <a class="btn btn-outline-secondary"
                       href="{{ route('admin.amial.fees.profit', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]) }}">هذا الشهر</a>
                    <a class="btn btn-outline-secondary"
                       href="{{ route('admin.amial.fees.profit', ['from' => now()->subDays(89)->toDateString(), 'to' => now()->toDateString()]) }}">٩٠ يوماً</a>
                </div>
            </form>

            <p class="text-muted small mb-0 mt-2">
                <i class="tio-info-outined"></i>
                النطاقُ محسوبٌ بتوقيت الخادم ({{ config('app.timezone') }})، ويشمل اليومين طرفيه كاملين.
                @if($from->diffInDays($to) >= 366)
                    <span class="text-warning fw-bold">— وقُصّ إلى ٣٦٦ يوماً.</span>
                @endif
            </p>
        </div>
    </div>

    {{-- ══ المؤشِّرات ══ --}}
    <div class="fee-kpis">
        <div class="fee-kpi">
            <span class="k-label">الرسومُ المحصَّلة (إجمالي)</span>
            <span class="k-value money">{{ $money($report['gross']) }}</span>
            <span class="k-note">ر.ي — من {{ number_format($report['transaction_count']) }} عمليّة</span>
        </div>

        <div class="fee-kpi is-success">
            <span class="k-label">ربحُ المنصّة (صافي)</span>
            <span class="k-value money">{{ $money($report['net']) }}</span>
            <span class="k-note">ر.ي — بعد حصص الوكلاء</span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">حصصُ الوكلاء</span>
            <span class="k-value money">{{ $money($report['agent_commission']) }}</span>
            <span class="k-note">ر.ي — مقيسةٌ من الدفتر لا مطروحة</span>
        </div>

        <div class="fee-kpi {{ $report['balanced'] ? '' : 'is-danger' }}">
            <span class="k-label">فرقٌ غيرُ مفسَّر</span>
            <span class="k-value money">{{ $money($report['unexplained']) }}</span>
            <span class="k-note">
                {{ $report['balanced'] ? 'المعادلةُ متوازنة' : 'إجمالي − صافي − حصص ≠ صفر' }}
            </span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">ربحُ اليوم</span>
            <span class="k-value money">{{ $money($report['today_net']) }}</span>
            <span class="k-note">ر.ي</span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">الربحُ التراكميّ</span>
            <span class="k-value money">{{ $money($report['lifetime_net']) }}</span>
            <span class="k-note">ر.ي — مسوّى {{ $money($report['reconciled']) }} · معلَّق {{ $money($report['pending']) }}</span>
        </div>
    </div>

    {{--
        **المعادلةُ تُعرَض لا تُخفى.**

        ورقمٌ لا يُفسَّر يُقال، ولا يُذاب في خانةٍ أخرى تجعل الجدولَ يتوازن.
    --}}
    @if(! $report['balanced'])
        <div class="alert alert-danger" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="tio-warning mt-1"></i>
                <div>
                    <strong>المعادلةُ لا تتوازن في هذه الفترة.</strong>
                    <div class="small mt-1 font-monospace" style="direction:ltr;text-align:right">
                        {{ $money($report['gross']) }} − {{ $money($report['net']) }}
                        − {{ $money($report['agent_commission']) }}
                        = <strong>{{ $money($report['unexplained']) }}</strong>
                    </div>
                    <div class="small mt-2">
                        رسمٌ حُصّل ولم يُقيَّد لأحد، أو قيدٌ لم يُرحَّل.
                        افتح <a href="{{ route('admin.amial.fees.drill', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">العمليّاتِ خلف الرقم</a>
                        لتتبّع أين انقطعت السلسلة.
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-success d-flex align-items-center gap-2 small" role="status">
            <i class="tio-checkmark-circle"></i>
            <span>
                المعادلةُ متوازنة: <span class="font-monospace" style="direction:ltr">
                {{ $money($report['gross']) }} = {{ $money($report['net']) }} + {{ $money($report['agent_commission']) }}</span>
                — كلُّ ريالٍ حُصّل له وجهةٌ معروفة.
            </span>
        </div>
    @endif

    <div class="row g-3">
        {{-- ══ المنحنى ══ --}}
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-header-title mb-0">ربحُ المنصّة يوماً بيوم</h5>
                </div>
                <div class="card-body">
                    @if(count($report['daily']) === 0)
                        <div class="fee-empty">
                            <i class="tio-chart-bar-4"></i>
                            <div>لا حركةَ في هذه الفترة</div>
                        </div>
                    @else
                        {{--
                            **منحنىً بلا مكتبةٍ خارجيّة.** الخادمُ في اليمن،
                            وأصلٌ من CDN يتوقّف عند أوّل انقطاع — وهو الوقتُ
                            الذي يُحتاج فيه التقرير.
                        --}}
                        <div style="display:flex;align-items:flex-end;gap:2px;height:180px;overflow-x:auto;padding-bottom:.25rem">
                            @foreach($report['daily'] as $d)
                                @php($h = max(2, (int) round(((float) $d['net'] / $maxDaily) * 170)))
                                <div style="flex:0 0 auto;width:10px;height:{{ $h }}px;
                                            background:var(--amial-primary-light);border-radius:2px 2px 0 0"
                                     title="{{ $d['date'] }} — {{ $money($d['net']) }} ر.ي"
                                     aria-label="{{ $d['date'] }}: {{ $money($d['net']) }} ريال"></div>
                            @endforeach
                        </div>
                        <div class="d-flex justify-content-between small text-muted mt-2">
                            <span>{{ $report['daily'][0]['date'] }}</span>
                            <span>أعلى يوم: {{ $money($maxDaily) }} ر.ي</span>
                            <span>{{ $report['daily'][count($report['daily']) - 1]['date'] }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ══ التفصيلُ بالعمليّة ══ --}}
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <h5 class="card-header-title mb-0">الرسومُ بحسب العمليّة</h5>
                    <a class="btn btn-sm btn-outline-primary ms-auto"
                       href="{{ route('admin.amial.fees.drill', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">
                        كلُّ العمليّات <i class="tio-chevron-left"></i>
                    </a>
                </div>

                <div class="fee-scroll">
                    <table class="table table-borderless table-thead-bordered table-align-middle mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th>العمليّة</th>
                            <th class="text-start">العدد</th>
                            <th class="text-start">الرسوم</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($report['by_type'] as $row)
                            <tr>
                                <td>
                                    {{-- **كلُّ رقمٍ يُنقَر إليه.** --}}
                                    <a href="{{ route('admin.amial.fees.drill', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'type' => $row['type']]) }}">
                                        {{ $row['label'] }}
                                    </a>
                                    @if($row['fee_code'])
                                        <br><small class="text-muted font-monospace">{{ $row['fee_code'] }}</small>
                                    @endif
                                </td>
                                <td class="money">{{ number_format($row['count']) }}</td>
                                <td class="money fw-bold">{{ $money($row['gross']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">
                                    <div class="fee-empty">
                                        <i class="tio-receipt"></i>
                                        <div>لا رسومَ محصَّلةً في هذه الفترة</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3">
        <i class="tio-info-outined"></i>
        الأرقامُ محسوبةٌ عشريّاً من الدفتر مباشرةً — لا من عمودٍ مخزَّنٍ ولا بحسابٍ عائم.
        و«الربحُ التراكميّ» يشمل الرسومَ المحصَّلةَ غيرَ المسوّاة بعد.
    </p>

</div>
@endsection
