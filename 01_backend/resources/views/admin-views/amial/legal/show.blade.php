@extends('layouts.admin.app')

@section('title', translate('Legal Terms') . ' v' . $term->version)

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('admin.amial.legal.index') }}" class="btn btn-soft-secondary btn-sm">
            <i class="tio-back-ui"></i>
        </a>
        <h2 class="page-header-title mb-0">
            v{{ $term->version }} — {{ strtoupper($term->locale) }}
        </h2>
        @if($term->is_current)
            <span class="badge badge-success">{{ translate('Current') }}</span>
        @elseif($term->superseded_at)
            <span class="badge badge-secondary">{{ translate('Superseded') }}</span>
        @endif
    </div>

    <div class="row">
        {{-- Stats sidebar --}}
        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-header-title">{{ translate('Acceptance Stats') }}</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-3">
                        <div>
                            <small class="text-muted">{{ translate('Accepted by') }}</small>
                            <h3 class="mb-0">{{ number_format($accepted_count) }}</h3>
                        </div>
                        <div>
                            <small class="text-muted">{{ translate('Total users') }}</small>
                            <h3 class="mb-0">{{ number_format($total_users) }}</h3>
                        </div>
                        <div>
                            <small class="text-muted">{{ translate('Acceptance rate') }}</small>
                            <h3 class="mb-0">{{ $acceptance_rate }}%</h3>
                            <div class="progress mt-2" style="height:6px">
                                <div class="progress-bar bg-success" style="width: {{ min($acceptance_rate, 100) }}%"></div>
                            </div>
                        </div>
                        <div>
                            <small class="text-muted">{{ translate('Effective at') }}</small>
                            <div class="text-monospace small">{{ $term->effective_at?->format('Y-m-d H:i') }}</div>
                        </div>
                        @if($term->superseded_at)
                            <div>
                                <small class="text-muted">{{ translate('Superseded at') }}</small>
                                <div class="text-monospace small">{{ $term->superseded_at?->format('Y-m-d H:i') }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Content --}}
        <div class="col-md-8">
            @if($term->changelog)
                <div class="card mb-3 border-warning">
                    <div class="card-body">
                        <strong>{{ translate('Changelog:') }}</strong>
                        <p class="mb-0 mt-2">{{ $term->changelog }}</p>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h5 class="card-header-title">{{ $term->title }}</h5>
                </div>
                <div class="card-body">
                    <pre style="white-space:pre-wrap;font-family:inherit;line-height:1.7">{{ $term->content }}</pre>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
