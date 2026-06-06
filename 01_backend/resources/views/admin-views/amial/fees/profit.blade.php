@extends('layouts.admin.app')

@section('title', translate('Profit Report') . ' — Amial Pay')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('admin.amial.fees.index') }}" class="btn btn-soft-secondary btn-sm"><i class="tio-back-ui"></i></a>
        <i class="tio-chart-bar-4" style="font-size:22px;color:#0B435B"></i>
        <h2 class="page-header-title mb-0">{{ translate('Profit Report') }}</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-PROFIT-REPORT-001</span>
    </div>

    {{-- فلتر النطاق الزمني --}}
    <form method="GET" action="{{ route('admin.amial.fees.profit') }}" class="card mb-4">
        <div class="card-body">
            <div class="row align-items-end g-2">
                <div class="col-md-4">
                    <label class="input-label">{{ translate('From') }}</label>
                    <input type="date" name="from" class="form-control" value="{{ $from->format('Y-m-d') }}">
                </div>
                <div class="col-md-4">
                    <label class="input-label">{{ translate('To') }}</label>
                    <input type="date" name="to" class="form-control" value="{{ $to->format('Y-m-d') }}">
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary" style="background:#0B435B;border-color:#0B435B">
                        <i class="tio-filter-list"></i> {{ translate('Apply') }}
                    </button>
                </div>
            </div>
        </div>
    </form>

    {{-- بطاقات ملخّص --}}
    <div class="row mb-4">
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card h-100" style="border-bottom:3px solid #0B435B">
                <div class="card-body">
                    <h6 class="text-muted mb-1">{{ translate('Net profit (period)') }}</h6>
                    <span class="h3" style="color:#0B435B">{{ number_format((float)$periodNet, 2) }}</span>
                    <small class="text-muted d-block">{{ translate('after agent commissions') }}</small>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card h-100" style="border-bottom:3px solid #F9C715">
                <div class="card-body">
                    <h6 class="text-muted mb-1">{{ translate('Gross fees (period)') }}</h6>
                    <span class="h3">{{ number_format((float)$periodGross, 2) }}</span>
                    <small class="text-muted d-block">{{ translate('all fees collected') }}</small>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card h-100" style="border-bottom:3px solid #6c757d">
                <div class="card-body">
                    <h6 class="text-muted mb-1">{{ translate('Agent commissions') }}</h6>
                    <span class="h3">{{ number_format((float)$periodAgentCommissions, 2) }}</span>
                    <small class="text-muted d-block">{{ translate('paid to agents') }}</small>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card h-100" style="border-bottom:3px solid #28a745">
                <div class="card-body">
                    <h6 class="text-muted mb-1">{{ translate('Today / Lifetime net') }}</h6>
                    <span class="h4 text-success">{{ number_format((float)$todayNet, 2) }}</span>
                    <small class="text-muted d-block">{{ translate('lifetime') }}: {{ number_format((float)$lifetimeNet, 2) }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- تفصيل حسب نوع العملية --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title">{{ translate('Fees by operation') }}
                <small class="text-muted">({{ $from->format('Y-m-d') }} → {{ $to->format('Y-m-d') }})</small>
            </h5>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>{{ translate('Operation') }}</th>
                    <th class="text-end">{{ translate('Count') }}</th>
                    <th class="text-end">{{ translate('Gross fees') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($grossByType as $row)
                    <tr>
                        <td><span class="text-monospace">{{ $row->transaction_type }}</span></td>
                        <td class="text-end">{{ number_format((int)$row->cnt) }}</td>
                        <td class="text-end fw-bold">{{ number_format((float)$row->gross, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted py-4">{{ translate('No fees collected in this period.') }}</td></tr>
                @endforelse
                </tbody>
                @if($grossByType->count() > 0)
                <tfoot>
                    <tr class="border-top">
                        <th>{{ translate('Total') }}</th>
                        <th class="text-end">{{ number_format((int)$grossByType->sum('cnt')) }}</th>
                        <th class="text-end" style="color:#0B435B">{{ number_format((float)$periodGross, 2) }}</th>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>

    <p class="text-muted mt-3"><small>
        <i class="tio-info-outined"></i>
        {{ translate('Net profit = gross fees − agent commissions. Source: transaction fee records (AMIAL-FEE-ENGINE-001).') }}
    </small></p>

</div>
@endsection
