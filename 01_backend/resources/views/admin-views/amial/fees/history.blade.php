@extends('layouts.admin.app')

@section('title', translate('Fee history') . ' — ' . $code)

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('admin.amial.fees.index') }}" class="btn btn-soft-secondary btn-sm"><i class="tio-back-ui"></i></a>
        <h2 class="page-header-title mb-0">{{ translate('History') }}: <span class="text-monospace">{{ $code }}</span></h2>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="card-header-title">{{ translate('Versions') }}</h5></div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>v</th>
                    <th>{{ translate('Applies to') }}</th>
                    <th>{{ translate('Type') }}</th>
                    <th>{{ translate('Percent') }}</th>
                    <th>{{ translate('Fixed') }}</th>
                    <th>{{ translate('Agent share') }}</th>
                    <th>{{ translate('Status') }}</th>
                    <th>{{ translate('Effective from') }}</th>
                    <th>{{ translate('Effective to') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($versions as $v)
                    <tr>
                        <td class="fw-bold text-monospace">{{ $v->version }}</td>
                        <td><small>{{ $v->applies_to }}</small></td>
                        <td><small class="text-monospace">{{ $v->fee_type }}</small></td>
                        <td>{{ rtrim(rtrim((string)$v->percent_rate,'0'),'.') }}%</td>
                        <td>{{ rtrim(rtrim((string)$v->fixed_amount,'0'),'.') }}</td>
                        <td><small>{{ rtrim(rtrim((string)$v->agent_commission_percent,'0'),'.') }}%</small></td>
                        <td>
                            @if($v->is_active)
                                <span class="badge badge-soft-success">{{ translate('Active') }}</span>
                            @else
                                <span class="badge badge-soft-secondary">{{ translate('Superseded') }}</span>
                            @endif
                        </td>
                        <td><small>{{ $v->effective_from?->format('Y-m-d H:i') }}</small></td>
                        <td><small>{{ $v->effective_to?->format('Y-m-d H:i') ?? '—' }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $versions->links() }}</div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="card-header-title">{{ translate('Change log (audit)') }}</h5></div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>{{ translate('When') }}</th>
                    <th>{{ translate('Action') }}</th>
                    <th>{{ translate('Admin') }}</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td><small>{{ $log->created_at?->format('Y-m-d H:i') }}</small></td>
                        <td>
                            @if($log->action === 'created')
                                <span class="badge badge-soft-success">{{ $log->action }}</span>
                            @elseif($log->action === 'deactivated')
                                <span class="badge badge-soft-danger">{{ $log->action }}</span>
                            @else
                                <span class="badge badge-soft-secondary">{{ $log->action }}</span>
                            @endif
                        </td>
                        <td><small>{{ $log->admin_id ?? '—' }}</small></td>
                        <td><small class="text-monospace">{{ $log->ip ?? '—' }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-3">{{ translate('No change log entries.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
