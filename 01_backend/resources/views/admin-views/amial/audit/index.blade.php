@extends('layouts.admin.app')

@section('title', translate('System Audit Log'))

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-search" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">{{ translate('System Audit Log') }}</h2>
        <span class="badge badge-soft-info ms-auto">audit_decisions</span>
    </div>

    {{-- Quick stats (last 24h) --}}
    <div class="row mb-4">
        @foreach(['info' => 'primary', 'notice' => 'info', 'warning' => 'warning', 'critical' => 'danger'] as $sev => $color)
            <div class="col-md-3">
                <div class="card border-{{ $color }}">
                    <div class="card-body py-3">
                        <small class="text-muted text-uppercase">{{ translate($sev) }} (24h)</small>
                        <h3 class="text-{{ $color }} mb-0">{{ number_format($stats_24h[$sev] ?? 0) }}</h3>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="card mb-3">
        <div class="card-body">
            <form action="{{ url()->current() }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Decision code') }}</label>
                    <input type="text" name="decision_code" class="form-control"
                           placeholder="TX_ZONE_BLOCKED, TX_OK, ..." value="{{ $filters['decision_code'] ?? '' }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ translate('Severity') }}</label>
                    <select name="severity" class="form-control">
                        <option value="">{{ translate('All') }}</option>
                        @foreach(['info', 'notice', 'warning', 'critical'] as $sev)
                            <option value="{{ $sev }}" {{ ($filters['severity'] ?? '') == $sev ? 'selected' : '' }}>{{ $sev }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ translate('Actor user ID') }}</label>
                    <input type="number" name="actor_user_id" class="form-control" value="{{ $filters['actor_user_id'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Action contains') }}</label>
                    <input type="text" name="action" class="form-control" value="{{ $filters['action'] ?? '' }}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">{{ translate('Filter') }}</button>
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ translate('Date from') }}</label>
                    <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Date to') }}</label>
                    <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                </div>

                {{-- AMIAL-SAFEPAY-AUDIT-001: تتبّع شيء بعينه (نزاع، مستخدم، تسوية)
                     من أوّل أثر له إلى آخره. تُملأ آلياً حين يُفتح السجلّ من
                     لوحة النزاعات، ولذلك تبقى محفوظة عبر الفلترة والتصفّح. --}}
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Subject type') }}</label>
                    <input type="text" name="subject_type" class="form-control"
                           placeholder="safe_payment, user, ..." value="{{ $filters['subject_type'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Subject ID') }}</label>
                    <input type="text" name="subject_id" class="form-control" dir="ltr"
                           value="{{ $filters['subject_id'] ?? '' }}">
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title">{{ translate('Decisions') }}</h5>
            <span class="badge badge-soft-secondary text-dark">{{ $decisions->total() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>{{ translate('Time') }}</th>
                    <th>{{ translate('Actor') }}</th>
                    <th>{{ translate('Action') }}</th>
                    <th>{{ translate('Decision') }}</th>
                    <th>{{ translate('Severity') }}</th>
                    <th>{{ translate('Reason') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($decisions as $d)
                    <tr>
                        <td><small class="text-monospace">{{ $d->created_at?->format('Y-m-d H:i:s') }}</small></td>
                        <td>
                            <small class="text-monospace">{{ $d->actor_type }}#{{ $d->actor_user_id }}</small>
                        </td>
                        <td><small class="fw-bold">{{ $d->action }}</small></td>
                        <td>
                            @php
                                $code = $d->decision_code ?? '';
                                $isBlocked = str_contains($code, 'BLOCKED') || str_contains($code, 'INSUFFICIENT') || str_contains($code, 'INVALID') || str_contains($code, 'DENIED');
                                $isOk = str_contains($code, 'OK') || str_contains($code, 'COMPLETED') || str_contains($code, 'ACCEPTED');
                                $codeClass = $isBlocked ? 'danger' : ($isOk ? 'success' : 'secondary');
                            @endphp
                            <span class="badge badge-soft-{{ $codeClass }} text-monospace">{{ $code }}</span>
                        </td>
                        <td>
                            @switch($d->severity)
                                @case('critical') <span class="badge bg-danger">{{ $d->severity }}</span> @break
                                @case('warning') <span class="badge bg-warning text-dark">{{ $d->severity }}</span> @break
                                @case('notice') <span class="badge bg-info">{{ $d->severity }}</span> @break
                                @default <span class="badge bg-light text-dark">{{ $d->severity }}</span>
                            @endswitch
                        </td>
                        <td>
                            <small>{{ \Illuminate\Support\Str::limit($d->reason, 100) }}</small>
                            @if($d->context)
                                <button class="btn btn-sm btn-link p-0 ms-1" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#ctx-{{ $d->id }}">
                                    <i class="tio-add"></i>
                                </button>
                                <div class="collapse mt-2" id="ctx-{{ $d->id }}">
                                    <pre class="bg-light p-2 small mb-0" style="max-height:200px;overflow:auto">{{ json_encode(is_string($d->context) ? json_decode($d->context, true) : $d->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="tio-search" style="font-size:48px;opacity:.3"></i>
                            <div class="mt-2">{{ translate('No audit decisions found') }}</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $decisions->links() }}
        </div>
    </div>
</div>
@endsection
