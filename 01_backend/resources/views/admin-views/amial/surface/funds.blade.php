@extends('layouts.admin.app')
@section('title', translate('صناديق العائلة'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:#053391">👨‍👩‍👧 {{ translate('صناديق العائلة') }}</h4>
    <div class="card border-0 shadow-sm" style="border-radius:16px"><div class="card-body">
        <table class="table align-middle">
            <thead><tr><th>{{ translate('الصندوق') }}</th><th>{{ translate('الأعضاء') }}</th><th>{{ translate('الرصيد') }}</th><th>{{ translate('الحالة') }}</th><th>{{ translate('أُنشئ') }}</th></tr></thead>
            <tbody>
            @forelse($funds as $f)
                <tr>
                    <td><b>{{ $f->name }}</b><br><small class="text-muted">{{ $f->fund_ulid }}</small></td>
                    <td>{{ $f->members_count }}</td>
                    <td>{{ number_format((float) ($f->balance ?? 0), 0) }}</td>
                    <td>{{ $f->status ?? '—' }}</td>
                    <td><small>{{ $f->created_at?->format('Y-m-d') }}</small></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">{{ translate('لا صناديق بعد') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $funds->links() }}
    </div></div>
</div>
@endsection
