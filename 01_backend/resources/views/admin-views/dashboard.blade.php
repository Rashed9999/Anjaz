@extends('layouts.admin.app')
{{-- AMIAL-ADMIN-DASH-002 — داشبورد احترافي: بطاقات حقيقية + تحليلات ١٢ شهراً --}}
@section('title', translate('Dashboard'))

@section('content')
<div class="content container-fluid" dir="rtl">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0" style="color:#053391">{{ translate('لوحة تحكم أميال باي') }}</h4>
            <small class="text-muted">{{ translate('نظرة حيّة على المنصّة') }} — {{ now()->format('Y-m-d') }}</small>
        </div>
        <a href="{{ route('admin.amial.hub.finance') }}" class="btn btn-sm text-white" style="background:#053391" data-testid="btn-finance">
            {{ translate('المركز المالي (بث حي)') }}
        </a>
    </div>

    {{-- بطاقات الإحصاء المالية --}}
    @php
        $cards = [
            ['label' => 'الأموال المتداولة (محافظ المستخدمين)', 'value' => number_format((float) $balance['used_balance'], 2), 'icon' => '💳', 'color' => '#053391'],
            ['label' => 'رصيد خزينة المنصّة', 'value' => number_format((float) $balance['unused_balance'], 2), 'icon' => '🏦', 'color' => '#1D4FB8'],
            ['label' => 'إجمالي التغذية (Cash In)', 'value' => number_format((float) $balance['total_balance'], 2), 'icon' => '📥', 'color' => '#12694E'],
            ['label' => 'أرباح الرسوم', 'value' => number_format((float) $balance['total_earned'], 2), 'icon' => '💰', 'color' => '#B8860B'],
            ['label' => 'عمليات اليوم', 'value' => number_format($data['today']['tx_count'] ?? 0), 'icon' => '⚡', 'color' => '#7B1FA2'],
            ['label' => 'حجم اليوم', 'value' => number_format((float) ($data['today']['tx_volume'] ?? 0), 0), 'icon' => '📊', 'color' => '#C0392B'],
        ];
    @endphp
    <div class="row g-3 mb-4">
        @foreach($cards as $c)
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100 border-0 shadow-sm" style="border-radius:14px;border-bottom:3px solid {{ $c['color'] }} !important">
                <div class="card-body py-3">
                    <span style="font-size:20px">{{ $c['icon'] }}</span>
                    <div class="fw-bold mt-1" style="font-size:17px;color:{{ $c['color'] }}">{{ $c['value'] }}</div>
                    <small class="text-muted" style="font-size:11px">{{ translate($c['label']) }}</small>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- عدّادات المستخدمين (روابط للمراكز) --}}
    @php
        $users = [
            ['label' => 'العملاء', 'value' => $data['counts']['customers'] ?? 0, 'href' => route('admin.amial.hub.customers')],
            ['label' => 'الوكلاء', 'value' => $data['counts']['agents'] ?? 0, 'href' => route('admin.amial.hub.agents')],
            ['label' => 'التجار', 'value' => $data['counts']['merchants'] ?? 0, 'href' => route('admin.amial.hub.merchants')],
        ];
    @endphp
    <div class="row g-3 mb-4">
        @foreach($users as $u)
        <div class="col-4">
            <a href="{{ $u['href'] }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-center py-3" style="border-radius:14px">
                    <div class="fw-bold" style="font-size:24px;color:#053391">{{ number_format($u['value']) }}</div>
                    <small class="text-muted">{{ translate($u['label']) }}</small>
                </div>
            </a>
        </div>
        @endforeach
    </div>

    <div class="row g-3">
        {{-- تحليلات ١٢ شهراً --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100" style="border-radius:16px">
                <div class="card-header bg-white fw-bold border-0" style="border-radius:16px 16px 0 0">
                    {{ translate('حجم المعاملات') }} — {{ now()->year }}
                </div>
                <div class="card-body">
                    @php
                        $vals = collect($transaction)->map(fn ($v) => (float) $v);
                        $maxMonth = max(1.0, (float) $vals->max());
                        $monthNames = ['ينا','فبر','مار','أبر','ماي','يون','يول','أغس','سبت','أكت','نوف','ديس'];
                    @endphp
                    <div class="d-flex align-items-end justify-content-between" style="height:220px;gap:6px">
                        @for($i = 1; $i <= 12; $i++)
                            @php
                                $v = (float) ($transaction[$i] ?? 0);
                                $h = (int) round(($v / $maxMonth) * 180);
                                $isMax = $v > 0 && $v >= $maxMonth;
                            @endphp
                            <div class="d-flex flex-column align-items-center flex-fill" title="{{ number_format($v, 0) }}">
                                <small class="text-muted" style="font-size:9px">{{ $v > 0 ? number_format($v / 1000, 0) . 'k' : '' }}</small>
                                <div style="width:100%;max-width:26px;height:{{ max($h, $v > 0 ? 4 : 1) }}px;border-radius:6px 6px 0 0;background:{{ $isMax ? '#FECA1E' : '#053391' }};opacity:{{ $v > 0 ? 1 : 0.15 }}"></div>
                                <small class="text-muted mt-1" style="font-size:10px">{{ $monthNames[$i - 1] }}</small>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
        </div>

        {{-- الأعلى تعاملاً --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3" style="border-radius:16px">
                <div class="card-header bg-white fw-bold border-0" style="border-radius:16px 16px 0 0">🏆 {{ translate('أعلى الوكلاء تعاملاً') }}</div>
                <ul class="list-group list-group-flush" data-testid="list-agents">
                    @forelse(($data['top_agents'] ?? []) as $row)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>{{ $row->user->f_name ?? '—' }} {{ $row->user->l_name ?? '' }}</span>
                            <span class="fw-bold" style="color:#053391">{{ number_format((float) ($row->total_transaction ?? 0), 0) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">{{ translate('لا بيانات بعد') }}</li>
                    @endforelse
                </ul>
            </div>
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header bg-white fw-bold border-0" style="border-radius:16px 16px 0 0">⭐ {{ translate('أعلى العملاء تعاملاً') }}</div>
                <ul class="list-group list-group-flush" data-testid="list-customers">
                    @forelse(($data['top_customers'] ?? []) as $row)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>{{ $row->user->f_name ?? '—' }} {{ $row->user->l_name ?? '' }}</span>
                            <span class="fw-bold" style="color:#053391">{{ number_format((float) ($row->total_transaction ?? 0), 0) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">{{ translate('لا بيانات بعد') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

</div>
@endsection
