@extends('layouts.admin.app')

@section('title', translate('Executive Dashboard'))

@push('css_or_js')
    {{-- تحديث تلقائي كل 60 ثانية --}}
    <meta http-equiv="refresh" content="60">
@endpush

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-chart-bar-1 text-primary" style="font-size:26px"></i>
        <div>
            <h2 class="page-header-title mb-0">{{ translate('Executive Dashboard') }}</h2>
            <small class="text-muted">{{ translate('Live overview') }} — {{ \Illuminate\Support\Carbon::parse($data['generated_at'])->format('Y-m-d H:i') }}</small>
        </div>
        <span class="badge badge-soft-primary ms-auto">{{ translate('Auto-refresh 60s') }}</span>
    </div>

    {{-- ====== الصف الأول: المؤشرات المالية ====== --}}
    <div class="row">
        @php
            $kpis = [
                ['💰', translate('Total wallet balances'), number_format((float)$data['wallets_total'], 2), 'primary'],
                ['💳', translate('Payments today (volume)'), number_format((float)$data['payments_today']['volume'], 2), 'info'],
                ['🧾', translate('Payments today (count)'), number_format($data['payments_today']['count']), 'info'],
                ['🛒', translate('Purchases today'), number_format($data['purchases_today']), 'success'],
            ];
        @endphp
        @foreach($kpis as [$icon, $label, $value, $color])
            <div class="col-sm-6 col-lg-3 mb-3">
                <div class="card h-100 border-{{ $color }}">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">{{ $icon }} {{ $label }}</h6>
                        <span class="h2 text-{{ $color }}">{{ $value }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ====== الصف الثاني: المستخدمون والأمان ====== --}}
    <div class="row">
        @php
            $kpis2 = [
                ['👥', translate('Active users today'), number_format($data['active_users_today']), 'dark'],
                ['🆕', translate('New users today'), number_format($data['new_users_today']), 'success'],
                ['⚠️', translate('Security alerts today'), number_format($data['security_alerts_today']['total']), 'warning'],
                ['🚫', translate('Suspended accounts'), number_format($data['suspended_accounts']), 'danger'],
            ];
        @endphp
        @foreach($kpis2 as [$icon, $label, $value, $color])
            <div class="col-sm-6 col-lg-3 mb-3">
                <div class="card h-100 border-{{ $color }}">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">{{ $icon }} {{ $label }}</h6>
                        <span class="h2 text-{{ $color }}">{{ $value }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row">
        {{-- ====== الإيرادات ====== --}}
        <div class="col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-header-title">📈 {{ translate('Revenue') }}</h5></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>{{ translate('Fees today') }}</span>
                        <strong>{{ number_format((float)$data['revenue']['fees_today'], 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>{{ translate('Total charge earned') }}</span>
                        <strong>{{ number_format((float)$data['revenue']['charge_earned_total'], 2) }}</strong>
                    </div>
                    <h6 class="text-muted">{{ translate('Active subscriptions') }}</h6>
                    @forelse($data['revenue']['active_subscriptions'] as $plan => $count)
                        <div class="d-flex justify-content-between">
                            <span class="badge badge-soft-secondary text-dark">{{ $plan }}</span>
                            <span>{{ $count }}</span>
                        </div>
                    @empty
                        <small class="text-muted">{{ translate('No data') }}</small>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ====== أكثر التجار نشاطاً ====== --}}
        <div class="col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-header-title">🏪 {{ translate('Most active merchants') }}</h5></div>
                <div class="table-responsive">
                    <table class="table table-borderless table-align-middle mb-0">
                        <thead class="thead-light"><tr><th>{{ translate('Merchant') }}</th><th>{{ translate('Tx') }}</th><th>{{ translate('Volume') }}</th></tr></thead>
                        <tbody>
                        @forelse($data['top_merchants'] as $m)
                            @php $m = (array) $m; @endphp
                            <tr>
                                <td>{{ trim($m['name'] ?? '') ?: ('#'.($m['to_user_id'] ?? '')) }}</td>
                                <td><span class="badge badge-soft-info">{{ $m['tx_count'] ?? 0 }}</span></td>
                                <td>{{ number_format((float)($m['volume'] ?? 0), 0) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">{{ translate('No data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ====== أكثر محطات الوقود مبيعاً ====== --}}
        <div class="col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-header-title">⛽ {{ translate('Top fuel stations') }}</h5></div>
                <div class="table-responsive">
                    <table class="table table-borderless table-align-middle mb-0">
                        <thead class="thead-light"><tr><th>{{ translate('Station') }}</th><th>{{ translate('Liters') }}</th><th>{{ translate('Sales') }}</th></tr></thead>
                        <tbody>
                        @forelse($data['top_fuel_stations'] as $s)
                            @php $s = (array) $s; @endphp
                            <tr>
                                <td>{{ $s['station_name'] ?? ('#'.($s['station_id'] ?? '')) }}</td>
                                <td>{{ number_format((float)($s['liters'] ?? 0), 0) }}</td>
                                <td>{{ number_format((float)($s['total'] ?? 0), 0) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">{{ translate('No data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ====== حالة الخوادم وواجهات الـ API ====== --}}
    <div class="card mb-4">
        <div class="card-header"><h5 class="card-header-title">🖥️ {{ translate('System & API status') }}</h5></div>
        <div class="card-body">
            <div class="row text-center">
                @php
                    $statuses = [
                        ['API', $data['system_status']['api']],
                        ['Database', $data['system_status']['database']],
                        ['Cache', $data['system_status']['cache']],
                    ];
                @endphp
                @foreach($statuses as [$label, $ok])
                    <div class="col-md-3 mb-2">
                        <span class="badge badge-soft-{{ $ok ? 'success' : 'danger' }} p-2 w-100">
                            <i class="tio-{{ $ok ? 'checkmark-circle' : 'clear-circle' }}"></i>
                            {{ $label }} — {{ $ok ? translate('OK') : translate('DOWN') }}
                        </span>
                    </div>
                @endforeach
                <div class="col-md-3 mb-2">
                    <span class="badge badge-soft-secondary text-dark p-2 w-100">
                        <i class="tio-time"></i> {{ translate('Queue depth') }}: {{ $data['system_status']['queue_depth'] ?? '—' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
