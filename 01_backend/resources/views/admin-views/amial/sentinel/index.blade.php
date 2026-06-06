@extends('layouts.admin.app')

@section('title', translate('Security Sentinel'))

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-security text-primary" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">{{ translate('Security Sentinel') }}</h2>
        <span class="badge badge-soft-primary ms-auto">{{ translate('Hidden IDS') }}</span>
    </div>

    {{-- نطاق زمني --}}
    <form method="GET" class="mb-3">
        <div class="btn-group btn-group-sm">
            @foreach([1=>'1h', 24=>'24h', 72=>'3d', 168=>'7d'] as $h => $label)
                <a href="?hours={{ $h }}"
                   class="btn {{ $hours == $h ? 'btn-primary' : 'btn-soft-secondary' }}">{{ $label }}</a>
            @endforeach
        </div>
    </form>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- بطاقات الإحصاء --}}
    <div class="row mb-4">
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-subtitle">{{ translate('Total events') }}</h6>
                    <span class="h2">{{ $stats['total'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card h-100 border-danger">
                <div class="card-body">
                    <h6 class="card-subtitle text-danger">{{ translate('Critical') }}</h6>
                    <span class="h2 text-danger">{{ $stats['critical'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card h-100 border-warning">
                <div class="card-body">
                    <h6 class="card-subtitle text-warning">{{ translate('Warning') }}</h6>
                    <span class="h2 text-warning">{{ $stats['warning'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card h-100 border-dark">
                <div class="card-body">
                    <h6 class="card-subtitle">{{ translate('Blocked IPs (active)') }}</h6>
                    <span class="h2">{{ $stats['blocked_now'] }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- أعلى عناوين IP --}}
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-header-title">{{ translate('Top offending IPs') }}</h5></div>
                <div class="table-responsive">
                    <table class="table table-borderless table-align-middle mb-0">
                        <thead class="thead-light">
                            <tr><th>{{ translate('IP') }}</th><th>{{ translate('Hits') }}</th><th>{{ translate('Max score') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                        @forelse($top_ips as $row)
                            <tr>
                                <td><code>{{ $row->ip_address }}</code></td>
                                <td><span class="badge badge-soft-secondary text-dark">{{ $row->hits }}</span></td>
                                <td>{{ $row->max_score }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('admin.amial.sentinel.block') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="ip_address" value="{{ $row->ip_address }}">
                                        <input type="hidden" name="reason" value="manual from dashboard">
                                        <input type="hidden" name="minutes" value="1440">
                                        <button class="btn btn-xs btn-soft-danger">{{ translate('Block') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted">{{ translate('No data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- العناوين المحظورة --}}
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-header-title">{{ translate('Blocked IPs') }}</h5></div>
                <div class="table-responsive">
                    <table class="table table-borderless table-align-middle mb-0">
                        <thead class="thead-light">
                            <tr><th>{{ translate('IP') }}</th><th>{{ translate('Reason') }}</th><th>{{ translate('Until') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                        @forelse($blocked as $b)
                            <tr>
                                <td><code>{{ $b->ip_address }}</code></td>
                                <td><small>{{ $b->reason }}</small></td>
                                <td><small>{{ $b->blocked_until ? $b->blocked_until->diffForHumans() : translate('permanent') }}</small></td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('admin.amial.sentinel.unblock') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="ip_address" value="{{ $b->ip_address }}">
                                        <button class="btn btn-xs btn-soft-success">{{ translate('Unblock') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted">{{ translate('No blocked IPs') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- أحدث الأحداث --}}
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <h5 class="card-header-title">{{ translate('Recent events') }}</h5>
            <form method="GET" class="ms-auto d-flex gap-2">
                <input type="hidden" name="hours" value="{{ $hours }}">
                <input type="text" name="ip" class="form-control form-control-sm" placeholder="IP" value="{{ $filters['ip'] ?? '' }}">
                <select name="severity" class="form-control form-control-sm">
                    <option value="">{{ translate('All') }}</option>
                    @foreach(['notice','warning','critical'] as $sev)
                        <option value="{{ $sev }}" {{ ($filters['severity'] ?? '')==$sev?'selected':'' }}>{{ $sev }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary">{{ translate('Filter') }}</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>{{ translate('Time') }}</th>
                    <th>{{ translate('Severity') }}</th>
                    <th>{{ translate('Score') }}</th>
                    <th>{{ translate('IP') }}</th>
                    <th>{{ translate('Method') }}</th>
                    <th>{{ translate('Path') }}</th>
                    <th>{{ translate('Signatures') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($events as $e)
                    @php
                        $badge = ['critical'=>'danger','warning'=>'warning','notice'=>'info','info'=>'secondary'][$e->severity] ?? 'secondary';
                    @endphp
                    <tr>
                        <td><small>{{ $e->created_at?->format('Y-m-d H:i:s') }}</small></td>
                        <td><span class="badge badge-soft-{{ $badge }}">{{ $e->severity }}</span></td>
                        <td>{{ $e->threat_score }}</td>
                        <td><code>{{ $e->ip_address }}</code></td>
                        <td><span class="badge badge-soft-secondary text-dark">{{ $e->method }}</span></td>
                        <td><small class="text-break">{{ $e->path }}</small></td>
                        <td><small class="text-muted">{{ implode(', ', (array) $e->signatures) }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted">{{ translate('No events') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $events->links() }}</div>
    </div>

</div>
@endsection
