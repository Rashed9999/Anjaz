@extends('layouts.admin.app')

@section('title', translate('Legal Terms') . ' — Amial Pay')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-document-text" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">{{ translate('Legal Terms Versions') }}</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-LEGAL-001</span>
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
                <h5 class="card-header-title">{{ translate('All versions') }}</h5>
                <span class="badge badge-soft-secondary text-dark">{{ $terms->total() }}</span>
            </div>
            <a href="{{ route('admin.amial.legal.create') }}" class="btn btn-primary">
                <i class="tio-add"></i> {{ translate('Publish new version') }}
            </a>
        </div>

        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>{{ translate('Version') }}</th>
                    <th>{{ translate('Locale') }}</th>
                    <th>{{ translate('Title') }}</th>
                    <th>{{ translate('Status') }}</th>
                    <th>{{ translate('Acceptances') }}</th>
                    <th>{{ translate('Effective at') }}</th>
                    <th class="text-center">{{ translate('Action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($terms as $term)
                    <tr>
                        <td>{{ $term->id }}</td>
                        <td><span class="text-monospace fw-bold">{{ $term->version }}</span></td>
                        <td>{{ strtoupper($term->locale) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($term->title, 60) }}</td>
                        <td>
                            @if($term->is_current)
                                <span class="badge badge-soft-success">{{ translate('Current') }}</span>
                            @elseif($term->superseded_at)
                                <span class="badge badge-soft-secondary">{{ translate('Superseded') }}</span>
                            @else
                                <span class="badge badge-soft-warning">{{ translate('Inactive') }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-soft-info">
                                {{ $term->acceptances()->count() }}
                            </span>
                        </td>
                        <td><small>{{ $term->effective_at?->format('Y-m-d H:i') }}</small></td>
                        <td class="text-center">
                            <a href="{{ route('admin.amial.legal.show', $term->id) }}" class="btn btn-sm btn-soft-secondary">
                                <i class="tio-visible"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="tio-document-text" style="font-size:48px;opacity:.3"></i>
                            <div class="mt-2">{{ translate('No legal terms published yet') }}</div>
                            <small>{{ translate('Click "Publish new version" to create the first one') }}</small>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $terms->links() }}
        </div>
    </div>
</div>
@endsection
