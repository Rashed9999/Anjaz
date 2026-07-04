@extends('layouts.admin.app')
{{-- AMIAL-ADMIN-UI-001: لوحة معلومات الإدارة (كانت مفقودة). --}}
@section('title', translate('Dashboard'))

@section('content')
<div class="content container-fluid">
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card stat-card p-3" data-testid="stat-agents">
                <div class="text-muted small">{{ translate('Top Agents') }}</div>
                <div class="fs-3 fw-bold">{{ isset($data['top_agents']) ? count($data['top_agents']) : 0 }}</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card stat-card p-3" data-testid="stat-customers">
                <div class="text-muted small">{{ translate('Top Customers') }}</div>
                <div class="fs-3 fw-bold">{{ isset($data['top_customers']) ? count($data['top_customers']) : 0 }}</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card stat-card p-3" data-testid="stat-transactions">
                <div class="text-muted small">{{ translate('Top Transactions') }}</div>
                <div class="fs-3 fw-bold">{{ isset($data['top_transactions']) ? count($data['top_transactions']) : 0 }}</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card stat-card p-3" data-testid="stat-links">
                <div class="text-muted small">{{ translate('Quick Actions') }}</div>
                <a href="{{ route('admin.amial.executive.index') }}" class="btn btn-sm btn-outline-primary mt-2"
                   data-testid="btn-executive">{{ translate('Executive Dashboard') }}</a>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card stat-card">
                <div class="card-header fw-bold">{{ translate('Top Agents') }}</div>
                <ul class="list-group list-group-flush" data-testid="list-agents">
                    @forelse(($data['top_agents'] ?? []) as $row)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>{{ $row->user->f_name ?? '—' }} {{ $row->user->l_name ?? '' }}</span>
                            <span class="text-muted">{{ number_format((float)($row->total_transaction ?? 0), 0) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">{{ translate('No data yet') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stat-card">
                <div class="card-header fw-bold">{{ translate('Top Customers') }}</div>
                <ul class="list-group list-group-flush" data-testid="list-customers">
                    @forelse(($data['top_customers'] ?? []) as $row)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>{{ $row->user->f_name ?? '—' }} {{ $row->user->l_name ?? '' }}</span>
                            <span class="text-muted">{{ number_format((float)($row->total_transaction ?? 0), 0) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">{{ translate('No data yet') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
