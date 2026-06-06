@extends('layouts.admin.app')

@section('title', translate('Zone Management') . ' — Amial Pay')

@section('content')
<div class="content container-fluid">

    {{-- Page header --}}
    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-globe" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">{{ translate('Zone Management') }}</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-ZONE-001</span>
    </div>

    {{-- Stats cards --}}
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="avatar avatar-circle avatar-success">
                            <i class="tio-checkmark"></i>
                        </div>
                        <div>
                            <small class="text-muted">{{ translate('SOUTH (can transact)') }}</small>
                            <h3 class="mb-0">{{ number_format($south_users) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="avatar avatar-circle avatar-warning">
                            <i class="tio-visible"></i>
                        </div>
                        <div>
                            <small class="text-muted">{{ translate('Other zones (read-only)') }}</small>
                            <h3 class="mb-0">{{ number_format($other_users) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="avatar avatar-circle avatar-primary">
                            <i class="tio-group"></i>
                        </div>
                        <div>
                            <small class="text-muted">{{ translate('Total users') }}</small>
                            <h3 class="mb-0">{{ number_format($total_users) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    {{-- Filters --}}
    <div class="card mb-3">
        <div class="card-body">
            <form action="{{ url()->current() }}" method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Zone') }}</label>
                    <select name="zone" class="form-control">
                        <option value="all" {{ $zone_filter == 'all' ? 'selected' : '' }}>{{ translate('All zones') }}</option>
                        @foreach($valid_zones as $z)
                            <option value="{{ $z }}" {{ $zone_filter == $z ? 'selected' : '' }}>{{ $z }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ translate('User type') }}</label>
                    <select name="type" class="form-control">
                        <option value="all" {{ $type_filter == 'all' ? 'selected' : '' }}>{{ translate('All') }}</option>
                        <option value="0" {{ $type_filter == '0' ? 'selected' : '' }}>{{ translate('Admin') }}</option>
                        <option value="1" {{ $type_filter == '1' ? 'selected' : '' }}>{{ translate('Agent') }}</option>
                        <option value="2" {{ $type_filter == '2' ? 'selected' : '' }}>{{ translate('Customer') }}</option>
                        <option value="3" {{ $type_filter == '3' ? 'selected' : '' }}>{{ translate('Merchant') }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ translate('Search (phone/name/email)') }}</label>
                    <input type="search" name="search" class="form-control" value="{{ $search }}" placeholder="{{ translate('Search') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">{{ translate('Filter') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Users table --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title">{{ translate('Users') }}</h5>
            <span class="badge badge-soft-secondary text-dark">{{ $users->total() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>{{ translate('Phone') }}</th>
                    <th>{{ translate('Name') }}</th>
                    <th>{{ translate('Type') }}</th>
                    <th>{{ translate('Current Zone') }}</th>
                    <th class="text-center">{{ translate('Action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>{{ $user->id }}</td>
                        <td><span class="text-monospace">{{ $user->phone }}</span></td>
                        <td>{{ $user->f_name }} {{ $user->l_name }}</td>
                        <td>
                            @switch($user->type)
                                @case(0) <span class="badge badge-soft-danger">{{ translate('Admin') }}</span> @break
                                @case(1) <span class="badge badge-soft-warning">{{ translate('Agent') }}</span> @break
                                @case(2) <span class="badge badge-soft-info">{{ translate('Customer') }}</span> @break
                                @case(3) <span class="badge badge-soft-primary">{{ translate('Merchant') }}</span> @break
                                @default <span class="badge badge-soft-secondary">?</span>
                            @endswitch
                        </td>
                        <td>
                            @if($user->zone_code === 'SOUTH')
                                <span class="badge badge-soft-success">{{ $user->zone_code }}</span>
                            @else
                                <span class="badge badge-soft-warning">{{ $user->zone_code ?? 'UNKNOWN' }}</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-soft-primary"
                                    data-bs-toggle="modal" data-bs-target="#zoneModal-{{$user->id}}">
                                <i class="tio-edit"></i> {{ translate('Change Zone') }}
                            </button>

                            {{-- Modal لتغيير الـ zone --}}
                            <div class="modal fade text-start" id="zoneModal-{{$user->id}}" tabindex="-1">
                                <div class="modal-dialog">
                                    <form action="{{ route('admin.amial.zones.update') }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $user->id }}">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">
                                                    {{ translate('Change zone for') }} {{ $user->phone }}
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ translate('New zone') }}</label>
                                                    <select name="zone" class="form-control" required>
                                                        @foreach($valid_zones as $z)
                                                            <option value="{{ $z }}" {{ $user->zone_code == $z ? 'selected' : '' }}>{{ $z }}</option>
                                                        @endforeach
                                                    </select>
                                                    <small class="form-text text-muted">
                                                        {{ translate('Only SOUTH allows financial operations. Other zones = read-only.') }}
                                                    </small>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">{{ translate('Reason (optional, for audit)') }}</label>
                                                    <textarea name="reason" class="form-control" rows="2" maxlength="500"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    {{ translate('Cancel') }}
                                                </button>
                                                <button type="submit" class="btn btn-primary">{{ translate('Save') }}</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            {{ translate('No users found') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $users->links() }}
        </div>
    </div>
</div>
@endsection
