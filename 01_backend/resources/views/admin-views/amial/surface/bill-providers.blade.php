@extends('layouts.admin.app')
@section('title', translate('مزوّدو الفواتير'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-1" style="color:var(--amial-primary)">⚡ {{ translate('مزوّدو دفع الفواتير') }}</h4>
    <small class="text-muted">{{ translate('طلبات اليوم') }}: {{ $ordersToday }}</small>
    @if(session('success'))<div class="alert alert-success mt-2">{{ session('success') }}</div>@endif
    <div class="card border-0 shadow-sm mt-3" style="border-radius:16px"><div class="card-body">
        <table class="table align-middle">
            <thead><tr><th>{{ translate('المزوّد') }}</th><th>{{ translate('النوع') }}</th><th>{{ translate('الطلبات') }}</th><th>{{ translate('الحالة') }}</th><th></th></tr></thead>
            <tbody>
            @forelse($providers as $p)
                <tr>
                    <td><b>{{ $p->display_name_ar ?? $p->name }}</b><br><small class="text-muted">{{ $p->code }}</small></td>
                    <td>{{ $p->integration_type }}</td>
                    <td>{{ $p->orders_count }}</td>
                    <td>{!! $p->is_active ? '<span class="badge bg-success">مفعّل</span>' : '<span class="badge bg-secondary">معطّل</span>' !!}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.amial.surface.bill-providers.toggle', $p->id) }}">@csrf
                            <button class="btn btn-sm {{ $p->is_active ? 'btn-outline-danger' : 'btn-success' }}">{{ $p->is_active ? translate('تعطيل') : translate('تفعيل') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">{{ translate('لا مزوّدون بعد') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div></div>
</div>
@endsection
