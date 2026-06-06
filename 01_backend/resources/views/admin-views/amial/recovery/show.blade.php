@extends('layouts.admin.app')

@section('title', translate('Recovery request') . ' — ' . $req->request_ulid)

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('admin.amial.recovery.index') }}" class="btn btn-soft-secondary btn-sm">
            <i class="tio-back-ui"></i>
        </a>
        <h2 class="page-header-title mb-0">{{ translate('Recovery Request Details') }}</h2>
        <span class="badge badge-soft-info ms-auto text-monospace">{{ $req->request_ulid }}</span>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    <div class="row">
        {{-- Main content --}}
        <div class="col-md-8">

            {{-- Status banner --}}
            <div class="card mb-3 border-{{ $req->status == 'approved' ? 'success' : ($req->status == 'rejected' ? 'danger' : 'warning') }}">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        @switch($req->status)
                            @case('pending_review')
                                <i class="tio-warning text-warning" style="font-size:36px"></i>
                                <div>
                                    <h4 class="mb-0">{{ translate('Awaiting your review') }}</h4>
                                    <small class="text-muted">{{ translate('Submitted') }} {{ $req->created_at?->diffForHumans() }}</small>
                                </div>
                                @break
                            @case('approved')
                                <i class="tio-checkmark-circle text-success" style="font-size:36px"></i>
                                <div>
                                    <h4 class="mb-0">{{ translate('Approved') }}</h4>
                                    @if($req->reviewer)
                                        <small class="text-muted">
                                            {{ translate('by') }} {{ $req->reviewer->f_name }} {{ $req->reviewer->l_name }}
                                            — {{ $req->reviewed_at?->format('Y-m-d H:i') }}
                                        </small>
                                    @endif
                                </div>
                                @break
                            @case('rejected')
                                <i class="tio-clear-circle text-danger" style="font-size:36px"></i>
                                <div>
                                    <h4 class="mb-0">{{ translate('Rejected') }}</h4>
                                    @if($req->reviewer)
                                        <small class="text-muted">
                                            {{ translate('by') }} {{ $req->reviewer->f_name }} {{ $req->reviewer->l_name }}
                                            — {{ $req->reviewed_at?->format('Y-m-d H:i') }}
                                        </small>
                                    @endif
                                </div>
                                @break
                            @default
                                <i class="tio-time text-info" style="font-size:36px"></i>
                                <div>
                                    <h4 class="mb-0">{{ $req->status }}</h4>
                                </div>
                        @endswitch
                    </div>
                </div>
            </div>

            {{-- Request details --}}
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-header-title">{{ translate('Request details') }}</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ translate('Request type') }}</dt>
                        <dd class="col-sm-8">
                            @if($req->request_type == 'phone_change_self')
                                <span class="badge badge-soft-info">{{ translate('Self-service phone change') }}</span>
                            @elseif($req->request_type == 'phone_change_lost_phone')
                                <span class="badge badge-soft-warning">{{ translate('Lost phone recovery') }}</span>
                            @else
                                <span class="badge badge-soft-secondary">{{ $req->request_type }}</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">{{ translate('Old phone') }}</dt>
                        <dd class="col-sm-8 text-monospace">{{ $req->old_phone }}</dd>

                        <dt class="col-sm-4">{{ translate('New phone') }}</dt>
                        <dd class="col-sm-8 text-monospace fw-bold">{{ $req->new_phone }}</dd>

                        <dt class="col-sm-4">{{ translate('Submitted at') }}</dt>
                        <dd class="col-sm-8">{{ $req->created_at?->format('Y-m-d H:i:s') }}</dd>

                        <dt class="col-sm-4">{{ translate('Expires at') }}</dt>
                        <dd class="col-sm-8">
                            {{ $req->expires_at?->format('Y-m-d H:i:s') }}
                            @if($req->expires_at?->isFuture())
                                <small class="text-muted">({{ $req->expires_at->diffForHumans() }})</small>
                            @else
                                <span class="badge badge-soft-danger">{{ translate('Expired') }}</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">{{ translate('IP address') }}</dt>
                        <dd class="col-sm-8 text-monospace">{{ $req->ip_address ?? '—' }}</dd>

                        @if($req->user_notes)
                            <dt class="col-sm-4">{{ translate('User notes') }}</dt>
                            <dd class="col-sm-8"><em>{{ $req->user_notes }}</em></dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Identification documents (lost-phone) --}}
            @if($req->request_type == 'phone_change_lost_phone' && $req->identification_documents)
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-header-title">{{ translate('Identification documents') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            @foreach($req->identification_documents as $doc)
                                <div class="col-md-4 mb-2">
                                    <div class="card">
                                        <div class="card-body p-2">
                                            <small class="text-monospace d-block mb-1">{{ basename($doc) }}</small>
                                            <a href="{{ asset('storage/' . $doc) }}" target="_blank" class="btn btn-sm btn-soft-secondary w-100">
                                                <i class="tio-visible"></i> {{ translate('View') }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Admin notes --}}
            @if($req->admin_notes)
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-header-title">{{ translate('Admin notes') }}</h5>
                    </div>
                    <div class="card-body">
                        <pre style="white-space:pre-wrap;font-family:inherit;margin:0">{{ $req->admin_notes }}</pre>
                    </div>
                </div>
            @endif

            {{-- Action buttons (only if pending_review) --}}
            @if($req->status == 'pending_review')
                <div class="card border-warning">
                    <div class="card-header bg-warning text-white">
                        <h5 class="card-header-title text-white">{{ translate('Action required') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex gap-3">
                            <button type="button" class="btn btn-success flex-fill"
                                    data-bs-toggle="modal" data-bs-target="#approveModal">
                                <i class="tio-checkmark"></i> {{ translate('Approve') }}
                            </button>
                            <button type="button" class="btn btn-danger flex-fill"
                                    data-bs-toggle="modal" data-bs-target="#rejectModal">
                                <i class="tio-clear"></i> {{ translate('Reject') }}
                            </button>
                        </div>
                        <small class="text-muted d-block mt-2">
                            <i class="tio-info-outined"></i>
                            {{ translate('Approval applies a 7-day security hold. The phone is changed automatically after the hold expires.') }}
                        </small>
                    </div>
                </div>
            @endif
        </div>

        {{-- Right sidebar: user info + risk --}}
        <div class="col-md-4">
            {{-- User card --}}
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-header-title">{{ translate('User') }}</h5>
                </div>
                <div class="card-body">
                    @if($req->user)
                        <h5 class="mb-1">{{ $req->user->f_name }} {{ $req->user->l_name }}</h5>
                        <small class="text-muted d-block mb-3">#{{ $req->user_id }}</small>

                        <dl class="row mb-0">
                            <dt class="col-5">{{ translate('Email') }}</dt>
                            <dd class="col-7 text-truncate"><small>{{ $req->user->email }}</small></dd>

                            <dt class="col-5">{{ translate('Type') }}</dt>
                            <dd class="col-7">
                                @switch($req->user->type)
                                    @case(1) {{ translate('Agent') }} @break
                                    @case(2) {{ translate('Customer') }} @break
                                    @case(3) {{ translate('Merchant') }} @break
                                    @default {{ translate('Other') }}
                                @endswitch
                            </dd>

                            <dt class="col-5">{{ translate('Zone') }}</dt>
                            <dd class="col-7">
                                <span class="badge badge-soft-{{ $req->user->zone_code == 'SOUTH' ? 'success' : 'warning' }}">
                                    {{ $req->user->zone_code }}
                                </span>
                            </dd>

                            <dt class="col-5">{{ translate('Joined') }}</dt>
                            <dd class="col-7"><small>{{ $req->user->created_at?->format('Y-m-d') }}</small></dd>

                            <dt class="col-5">{{ translate('KYC') }}</dt>
                            <dd class="col-7">
                                @if($req->user->is_kyc_verified)
                                    <span class="badge badge-soft-success">{{ translate('Verified') }}</span>
                                @else
                                    <span class="badge badge-soft-warning">{{ translate('Not verified') }}</span>
                                @endif
                            </dd>
                        </dl>
                    @else
                        <em class="text-muted">{{ translate('User no longer exists') }}</em>
                    @endif
                </div>
            </div>

            {{-- Risk score --}}
            @if($req->risk_score !== null)
                @php
                    $riskClass = $req->risk_score >= 60 ? 'danger' : ($req->risk_score >= 30 ? 'warning' : 'success');
                @endphp
                <div class="card mb-3 border-{{ $riskClass }}">
                    <div class="card-header">
                        <h5 class="card-header-title">{{ translate('Risk Score') }}</h5>
                    </div>
                    <div class="card-body text-center">
                        <h1 class="text-{{ $riskClass }} mb-0">{{ $req->risk_score }}</h1>
                        <small class="text-muted">/ 100</small>
                        <div class="progress mt-2" style="height:8px">
                            <div class="progress-bar bg-{{ $riskClass }}" style="width: {{ $req->risk_score }}%"></div>
                        </div>
                        <small class="d-block mt-2 text-muted">
                            @if($req->risk_score >= 60)
                                {{ translate('HIGH risk — review documents carefully') }}
                            @elseif($req->risk_score >= 30)
                                {{ translate('MEDIUM risk — verify identity') }}
                            @else
                                {{ translate('LOW risk') }}
                            @endif
                        </small>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Approve modal --}}
    @if($req->status == 'pending_review')
        <div class="modal fade text-start" id="approveModal" tabindex="-1">
            <div class="modal-dialog">
                <form action="{{ route('admin.amial.recovery.approve', $req->request_ulid) }}" method="POST">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ translate('Approve recovery request') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="tio-warning"></i>
                                {{ translate('Approving will:') }}
                                <ul class="mb-0 mt-2">
                                    <li>{{ translate('Apply a 7-day security hold on the account') }}</li>
                                    <li>{{ translate('Revoke all access tokens') }}</li>
                                    <li>{{ translate('Disable FCM notifications on old device') }}</li>
                                    <li>{{ translate('Automatically change the phone number after the hold expires') }}</li>
                                </ul>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ translate('Admin notes (optional)') }}</label>
                                <textarea name="admin_notes" class="form-control" rows="3" maxlength="2000"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ translate('Cancel') }}</button>
                            <button type="submit" class="btn btn-success">
                                <i class="tio-checkmark"></i> {{ translate('Confirm approval') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade text-start" id="rejectModal" tabindex="-1">
            <div class="modal-dialog">
                <form action="{{ route('admin.amial.recovery.reject', $req->request_ulid) }}" method="POST">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ translate('Reject recovery request') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label required-mark">{{ translate('Reason') }}</label>
                                <textarea name="reason" class="form-control" rows="4" required minlength="5" maxlength="2000"
                                          placeholder="{{ translate('Why are you rejecting this request?') }}"></textarea>
                                <small class="form-text text-muted">
                                    {{ translate('This reason will be shown to the user.') }}
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ translate('Cancel') }}</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="tio-clear"></i> {{ translate('Reject') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection
