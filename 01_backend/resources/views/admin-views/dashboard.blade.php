@extends('layouts.admin.app')
{{-- AMIAL-ADMIN-DASH-003 — لوحة تحكم احترافية: شريط حالة + KPIs + رسم + متصدّرون --}}
@section('title', translate('Dashboard'))

@push('css_or_js')
<style>
    .amdash { --blue:#053391; --blue2:#1D4FB8; --gold:#FECA1E; --ink:#1A2433; --muted:#8B97A8; }
    .amdash .a-card { background:#fff; border:1px solid #eef1f5; border-radius:16px; }
    .amdash .a-soft { width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px; }
    .amdash .a-kpi-val { font-size:20px;font-weight:800;color:var(--ink);line-height:1.1; }
    .amdash .a-kpi-lbl { font-size:11.5px;color:var(--muted); }
    .amdash .a-hero { background:linear-gradient(135deg,var(--blue),var(--blue2)); border-radius:20px;color:#fff; }
    .amdash .a-chip { font-size:11px;padding:3px 10px;border-radius:20px;font-weight:700; }
    .amdash .a-rank { width:26px;height:26px;border-radius:8px;background:#f1f4f9;color:var(--blue);font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center; }
    .amdash .a-bar { width:100%;max-width:24px;border-radius:7px 7px 0 0;transition:.2s; }
    .amdash .a-bar:hover { opacity:.85 !important; }
</style>
@endpush

@section('content')
<div class="content container-fluid amdash" dir="rtl">

    {{-- ==== Header ==== --}}
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1" style="color:var(--blue)">{{ translate('لوحة تحكم أميال باي') }}</h4>
            <span class="a-chip" style="background:#e7f6ec;color:#12694E">● {{ translate('جميع الأنظمة تعمل') }}</span>
            <small class="text-muted ms-2">{{ now()->format('Y-m-d — H:i') }}</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.transaction.index') }}" class="btn btn-sm btn-outline-secondary">{{ translate('كشف المعاملات') }}</a>
            <a href="{{ route('admin.amial.hub.finance') }}" class="btn btn-sm text-white" style="background:var(--blue)" data-testid="btn-finance">
                {{ translate('المركز المالي') }}
            </a>
        </div>
    </div>

    {{-- ==== Hero: نبض اليوم ==== --}}
    @php
        $yearVals = collect($transaction)->map(fn($v)=>(float)$v);
        $yearTotal = (float) $yearVals->sum();
        $treasury = (float) ($balance['unused_balance'] ?? 0);
        $circulating = (float) ($balance['used_balance'] ?? 0);
    @endphp
    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="a-hero p-4 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div style="opacity:.8;font-size:12px">{{ translate('حجم عمليات اليوم') }}</div>
                        <div class="fw-bold mt-1" style="font-size:30px">{{ number_format((float)($data['today']['tx_volume'] ?? 0), 0) }} <span style="font-size:15px;opacity:.8">ر.ي</span></div>
                    </div>
                    <span class="a-chip" style="background:rgba(255,255,255,.18)">⚡ {{ number_format($data['today']['tx_count'] ?? 0) }} {{ translate('عملية') }}</span>
                </div>
                <hr style="border-color:rgba(255,255,255,.2)">
                <div class="row text-center">
                    <div class="col"><div class="fw-bold" style="font-size:16px">{{ number_format($circulating, 0) }}</div><small style="opacity:.75;font-size:10.5px">{{ translate('أموال متداولة') }}</small></div>
                    <div class="col"><div class="fw-bold" style="font-size:16px">{{ number_format($treasury, 0) }}</div><small style="opacity:.75;font-size:10.5px">{{ translate('خزينة المنصّة') }}</small></div>
                    <div class="col"><div class="fw-bold" style="font-size:16px">{{ number_format((float)($balance['total_earned'] ?? 0), 0) }}</div><small style="opacity:.75;font-size:10.5px">{{ translate('أرباح الرسوم') }}</small></div>
                </div>
            </div>
        </div>

        {{-- ==== KPI grid ==== --}}
        <div class="col-lg-7">
            @php
                $kpis = [
                    ['lbl'=>'إجمالي التغذية (Cash In)','val'=>number_format((float)$balance['total_balance'],0),'ic'=>'📥','bg'=>'#e9f2ff','fg'=>'#053391'],
                    ['lbl'=>'أرباح الرسوم','val'=>number_format((float)$balance['total_earned'],0),'ic'=>'💰','bg'=>'#fff6e0','fg'=>'#B8860B'],
                    ['lbl'=>'حجم السنة','val'=>number_format($yearTotal,0),'ic'=>'📊','bg'=>'#eafaf1','fg'=>'#12694E'],
                    ['lbl'=>'العملاء','val'=>number_format($data['counts']['customers'] ?? 0),'ic'=>'👤','bg'=>'#f0ecff','fg'=>'#5B2A9E','href'=>route('admin.amial.hub.customers')],
                    ['lbl'=>'الوكلاء','val'=>number_format($data['counts']['agents'] ?? 0),'ic'=>'🏪','bg'=>'#fdeef0','fg'=>'#C0392B','href'=>route('admin.amial.hub.agents')],
                    ['lbl'=>'التجار','val'=>number_format($data['counts']['merchants'] ?? 0),'ic'=>'🛒','bg'=>'#e8f7f6','fg'=>'#0E7C7B','href'=>route('admin.amial.hub.merchants')],
                ];
            @endphp
            <div class="row g-3 h-100">
                @foreach($kpis as $k)
                <div class="col-6 col-md-4">
                    @php $tag = isset($k['href']) ? 'a' : 'div'; @endphp
                    <{{ $tag }} @isset($k['href']) href="{{ $k['href'] }}" @endisset class="a-card p-3 h-100 d-block text-decoration-none">
                        <div class="a-soft mb-2" style="background:{{ $k['bg'] }}">{{ $k['ic'] }}</div>
                        <div class="a-kpi-val">{{ $k['val'] }}</div>
                        <div class="a-kpi-lbl">{{ translate($k['lbl']) }}</div>
                    </{{ $tag }}>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ==== Chart + Leaderboards ==== --}}
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="a-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold" style="color:var(--ink)">{{ translate('حجم المعاملات') }} — {{ now()->year }}</span>
                    <small class="text-muted">{{ translate('الإجمالي') }}: {{ number_format($yearTotal,0) }} ر.ي</small>
                </div>
                @php
                    $maxMonth = max(1.0, (float)$yearVals->max());
                    $monthNames = ['ينا','فبر','مار','أبر','ماي','يون','يول','أغس','سبت','أكت','نوف','ديس'];
                @endphp
                <div class="d-flex align-items-end justify-content-between" style="height:210px;gap:6px;border-bottom:1px solid #eef1f5;padding-bottom:6px">
                    @for($i=1;$i<=12;$i++)
                        @php
                            $v=(float)($transaction[$i] ?? 0);
                            $h=(int)round(($v/$maxMonth)*170);
                            $isMax = $v>0 && $v>=$maxMonth;
                        @endphp
                        <div class="d-flex flex-column align-items-center flex-fill" title="{{ number_format($v,0) }} ر.ي">
                            <small class="text-muted" style="font-size:9px">{{ $v>0 ? number_format($v/1000,0).'k' : '' }}</small>
                            <div class="a-bar" style="height:{{ max($h,$v>0?4:1) }}px;background:{{ $isMax ? 'var(--gold)' : 'linear-gradient(180deg,#1D4FB8,#053391)' }};opacity:{{ $v>0?1:.12 }}"></div>
                            <small class="text-muted mt-1" style="font-size:10px">{{ $monthNames[$i-1] }}</small>
                        </div>
                    @endfor
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            @php
                $boards = [
                    ['t'=>'🏆 '.translate('أعلى الوكلاء تعاملاً'),'rows'=>$data['top_agents'] ?? [],'tid'=>'list-agents'],
                    ['t'=>'⭐ '.translate('أعلى العملاء تعاملاً'),'rows'=>$data['top_customers'] ?? [],'tid'=>'list-customers'],
                ];
            @endphp
            @foreach($boards as $b)
            <div class="a-card mb-3">
                <div class="p-3 pb-2 fw-bold" style="color:var(--ink)">{{ $b['t'] }}</div>
                <div data-testid="{{ $b['tid'] }}">
                    @forelse($b['rows'] as $idx => $row)
                        <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-top:1px solid #f3f5f8">
                            <div class="d-flex align-items-center gap-2">
                                <span class="a-rank">{{ $idx+1 }}</span>
                                <span>{{ $row->user->f_name ?? '—' }} {{ $row->user->l_name ?? '' }}</span>
                            </div>
                            <span class="fw-bold" style="color:var(--blue)">{{ number_format((float)($row->total_transaction ?? 0),0) }}</span>
                        </div>
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
