@extends('layouts.admin.app')

@section('title', translate('Account Recovery Requests'))

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-shield-outlined" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">{{ translate('Account Recovery Requests') }}</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-RECOVERY-001</span>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- Status filter tabs --}}
    <ul class="nav nav-tabs mb-3">
        @foreach(['pending_review' => 'Pending review', 'pending_otp' => 'Pending OTP', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ request('status', 'pending_review') == $key ? 'active' : '' }}"
                   href="{{ url()->current() }}?status={{ $key }}">
                    {{ translate($label) }}
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title">
                {{ translate('Requests') }} —
                <span class="text-muted">{{ request('status', 'pending_review') }}</span>
            </h5>
            <span class="badge badge-soft-secondary text-dark ms-auto">{{ $requests->total() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>{{ translate('Submitted') }}</th>
                    <th>{{ translate('User') }}</th>
                    <th>{{ translate('Type') }}</th>
                    <th>{{ translate('Old phone') }}</th>
                    <th>{{ translate('New phone') }}</th>
                    <th>{{ translate('Status') }}</th>
                    <th>{{ translate('Risk') }}</th>
                    <th class="text-center">{{ translate('Action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($requests as $req)
                    <tr>
                        <td><small>{{ $req->created_at?->diffForHumans() }}</small></td>
                        <td>
                            @if($req->user)
                                <div class="fw-bold">{{ $req->user->f_name }} {{ $req->user->l_name }}</div>
                                <small class="text-muted">#{{ $req->user_id }}</small>
                            @else
                                <span class="text-muted">User #{{ $req->user_id }} (deleted)</span>
                            @endif
                        </td>
                        <td>
                            @if($req->request_type == 'phone_change_self')
                                <span class="badge badge-soft-info">{{ translate('Self') }}</span>
                            @elseif($req->request_type == 'phone_change_lost_phone')
                                <span class="badge badge-soft-warning">{{ translate('Lost phone') }}</span>
                            @else
                                <span class="badge badge-soft-secondary">{{ $req->request_type }}</span>
                            @endif
                        </td>
                        <td><small class="text-monospace">{{ $req->old_phone }}</small></td>
                        <td><small class="text-monospace fw-bold">{{ $req->new_phone }}</small></td>
                        <td>
                            @switch($req->status)
                                @case('pending_review')
                                    <span class="badge badge-soft-warning">{{ translate('Pending review') }}</span>
                                    @break
                                @case('pending_otp')
                                    <span class="badge badge-soft-info">{{ translate('Pending OTP') }}</span>
                                    @break
                                @case('approved')
                                    <span class="badge badge-soft-success">{{ translate('Approved') }}</span>
                                    @break
                                @case('rejected')
                                    <span class="badge badge-soft-danger">{{ translate('Rejected') }}</span>
                                    @break
                                @case('expired')
                                    <span class="badge badge-soft-secondary">{{ translate('Expired') }}</span>
                                    @break
                                @default
                                    <span class="badge badge-soft-secondary">{{ $req->status }}</span>
                            @endswitch
                        </td>
                        <td>
                            @if($req->risk_score !== null)
                                @php
                                    $riskClass = $req->risk_score >= 60 ? 'danger' : ($req->risk_score >= 30 ? 'warning' : 'success');
                                @endphp
                                <span class="badge badge-soft-{{ $riskClass }}">{{ $req->risk_score }}/100</span>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td class="text-center">
                            <a href="{{ route('admin.amial.recovery.show', $req->request_ulid) }}"
                               class="btn btn-sm btn-soft-primary">
                                <i class="tio-visible"></i> {{ translate('Review') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="tio-shield-outlined" style="font-size:48px;opacity:.3"></i>
                            <div class="mt-2">{{ translate('No recovery requests found') }}</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $requests->links() }}
        </div>
    </div>
</div>
@endsection
