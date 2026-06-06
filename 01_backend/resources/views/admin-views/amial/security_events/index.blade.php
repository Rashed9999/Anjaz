@extends('layouts.admin.app')

@section('title', translate('Security Events'))

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-warning text-danger" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">{{ translate('Account Security Events') }}</h2>
        <span class="badge badge-soft-danger ms-auto">account_security_events</span>
    </div>

    {{-- Brute-force alert (suspicious users in last hour) --}}
    @if($suspicious_users->isNotEmpty())
        <div class="alert alert-danger">
            <h5 class="alert-heading">
                <i class="tio-warning"></i> {{ translate('Suspicious activity detected (last hour)') }}
            </h5>
            <p class="mb-2">{{ translate('Users with multiple failed PIN attempts:') }}</p>
            <ul class="mb-0">
                @foreach($suspicious_users as $sus)
                    <li>
                        <strong>User #{{ $sus->user_id }}</strong>
                        — {{ $sus->failed_count }} {{ translate('failed PIN attempts') }}
                        <a href="?user_id={{ $sus->user_id }}" class="btn btn-sm btn-soft-danger ms-2">
                            {{ translate('View events') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Filters --}}
    <div class="card mb-3">
        <div class="card-body">
            <form action="{{ url()->current() }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">{{ translate('User ID') }}</label>
                    <input type="number" name="user_id" class="form-control" value="{{ $filters['user_id'] ?? '' }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ translate('Event type contains') }}</label>
                    <input type="text" name="event_type" class="form-control"
                           placeholder="PIN_FAILED, PHONE_CHANGED, ..." value="{{ $filters['event_type'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Severity') }}</label>
                    <select name="severity" class="form-control">
                        <option value="">{{ translate('All') }}</option>
                        @foreach(['info', 'notice', 'warning', 'critical'] as $sev)
                            <option value="{{ $sev }}" {{ ($filters['severity'] ?? '') == $sev ? 'selected' : '' }}>{{ $sev }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">{{ translate('Filter') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title">{{ translate('Events') }}</h5>
            <span class="badge badge-soft-secondary text-dark">{{ $events->total() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>{{ translate('Time') }}</th>
                    <th>{{ translate('User') }}</th>
                    <th>{{ translate('Event type') }}</th>
                    <th>{{ translate('Severity') }}</th>
                    <th>{{ translate('IP') }}</th>
                    <th>{{ translate('Details') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($events as $ev)
                    <tr>
                        <td><small class="text-monospace">{{ $ev->created_at?->format('Y-m-d H:i:s') }}</small></td>
                        <td>
                            @if($ev->user)
                                <div class="fw-bold">{{ $ev->user->f_name }} {{ $ev->user->l_name }}</div>
                                <small class="text-monospace">#{{ $ev->user_id }} • {{ $ev->user->phone }}</small>
                            @else
                                <small class="text-muted">#{{ $ev->user_id }}</small>
                            @endif
                        </td>
                        <td>
                            @php
                                $type = $ev->event_type;
                                $isCritical = in_array($type, ['PHONE_CHANGED', 'PIN_LOCKED', 'PHONE_CHANGE_ADMIN_APPROVED']);
                                $isWarning = str_contains($type, 'FAILED') || str_contains($type, 'PENDING');
                                $typeClass = $isCritical ? 'danger' : ($isWarning ? 'warning' : 'info');
                            @endphp
                            <span class="badge badge-soft-{{ $typeClass }} text-monospace">{{ $type }}</span>
                        </td>
                        <td>
                            @switch($ev->severity)
                                @case('critical') <span class="badge bg-danger">{{ $ev->severity }}</span> @break
                                @case('warning') <span class="badge bg-warning text-dark">{{ $ev->severity }}</span> @break
                                @case('notice') <span class="badge bg-info">{{ $ev->severity }}</span> @break
                                @default <span class="badge bg-light text-dark">{{ $ev->severity ?? 'info' }}</span>
                            @endswitch
                        </td>
                        <td><small class="text-monospace">{{ $ev->ip_address ?? '—' }}</small></td>
                        <td>
                            <small>{{ \Illuminate\Support\Str::limit($ev->note ?? '', 80) }}</small>
                            @if($ev->metadata)
                                <button class="btn btn-sm btn-link p-0 ms-1" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#meta-{{ $ev->id }}">
                                    <i class="tio-add"></i>
                                </button>
                                <div class="collapse mt-2" id="meta-{{ $ev->id }}">
                                    <pre class="bg-light p-2 small mb-0" style="max-height:200px;overflow:auto">{{ json_encode(is_string($ev->metadata) ? json_decode($ev->metadata, true) : $ev->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="tio-warning" style="font-size:48px;opacity:.3"></i>
                            <div class="mt-2">{{ translate('No security events found') }}</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $events->links() }}
        </div>
    </div>
</div>
@endsection
