@extends('layouts.admin.app')

@section('title', translate('Fee & Profit Control') . ' — Amial Pay')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-percent" style="font-size:24px;color:#0B435B"></i>
        <h2 class="page-header-title mb-0">{{ translate('Fee & Profit Control') }}</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-FEE-ENGINE-001</span>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h5 class="card-header-title">{{ translate('Active fee schemes') }}</h5>
                <span class="badge badge-soft-secondary text-dark">{{ translate('Zone') }}: {{ $zone }}</span>
                <span class="badge badge-soft-secondary text-dark">{{ $active->count() }}</span>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.amial.fees.profit') }}" class="btn btn-soft-primary">
                    <i class="tio-chart-bar-4"></i> {{ translate('Profit Report') }}
                </a>
                <a href="{{ route('admin.amial.fees.create') }}" class="btn btn-primary" style="background:#0B435B;border-color:#0B435B">
                    <i class="tio-add"></i> {{ translate('New version') }}
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>{{ translate('Operation') }}</th>
                    <th>{{ translate('Applies to') }}</th>
                    <th>{{ translate('Type') }}</th>
                    <th>{{ translate('Percent') }}</th>
                    <th>{{ translate('Fixed') }}</th>
                    <th>{{ translate('Min / Max') }}</th>
                    <th>{{ translate('Agent share') }}</th>
                    <th>{{ translate('Bearer') }}</th>
                    <th>v</th>
                    <th class="text-center">{{ translate('Action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($active as $fee)
                    <tr>
                        <td>
                            <span class="fw-bold">{{ $fee->label ?: $fee->code }}</span><br>
                            <small class="text-muted text-monospace">{{ $fee->code }}</small>
                        </td>
                        <td><span class="badge badge-soft-secondary">{{ $fee->code === $fee->code ? translate($fee->applies_to) : '' }}</span></td>
                        <td><small class="text-monospace">{{ $fee->fee_type }}</small></td>
                        <td>{{ rtrim(rtrim((string)$fee->percent_rate, '0'), '.') }}%</td>
                        <td>{{ rtrim(rtrim((string)$fee->fixed_amount, '0'), '.') }}</td>
                        <td>
                            <small>{{ $fee->min_fee !== null ? rtrim(rtrim((string)$fee->min_fee,'0'),'.') : '—' }}
                            / {{ $fee->max_fee !== null ? rtrim(rtrim((string)$fee->max_fee,'0'),'.') : '∞' }}</small>
                        </td>
                        <td>
                            @if((float)$fee->agent_commission_percent > 0 || (float)$fee->agent_commission_fixed > 0)
                                <span class="badge badge-soft-warning">
                                    {{ rtrim(rtrim((string)$fee->agent_commission_percent,'0'),'.') }}%
                                    @if((float)$fee->agent_commission_fixed > 0) + {{ rtrim(rtrim((string)$fee->agent_commission_fixed,'0'),'.') }} @endif
                                </span>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td><small>{{ translate($fee->bearer) }}</small></td>
                        <td><span class="text-monospace fw-bold">{{ $fee->version }}</span></td>
                        <td class="text-center">
                            <div class="btn-group">
                                <a href="{{ route('admin.amial.fees.create', ['code' => $fee->code, 'zone' => $fee->zone_code, 'applies_to' => $fee->applies_to]) }}"
                                   class="btn btn-sm btn-soft-primary" title="{{ translate('Edit (new version)') }}">
                                    <i class="tio-edit"></i>
                                </a>
                                <a href="{{ route('admin.amial.fees.history', $fee->code) }}"
                                   class="btn btn-sm btn-soft-secondary" title="{{ translate('History') }}">
                                    <i class="tio-history"></i>
                                </a>
                                <form action="{{ route('admin.amial.fees.deactivate', $fee->id) }}" method="POST"
                                      onsubmit="return confirm('{{ translate('Deactivate this fee? The operation will become free until a new version is set.') }}')">
                                    @csrf
                                    <button class="btn btn-sm btn-soft-danger" title="{{ translate('Deactivate') }}">
                                        <i class="tio-clear"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">{{ translate('No active fee schemes. Run the seeder or create one.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted mt-3"><small>
        <i class="tio-info-outined"></i>
        {{ translate('Editing creates a NEW version and supersedes the old one. Old versions are never deleted (auditable).') }}
    </small></p>

</div>
@endsection
