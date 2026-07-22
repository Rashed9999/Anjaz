@extends('layouts.admin.app')
@section('title', translate('طلبات الأموال'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0" style="color:#053391">📨 {{ translate('طلبات الأموال (روابط/QR)') }}</h4>
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                <option value="">{{ translate('كل الحالات') }}</option>
                @foreach(['pending' => 'معلّق', 'paid' => 'مدفوع', 'cancelled' => 'ملغى', 'expired' => 'منتهٍ'] as $k => $v)
                    <option value="{{ $k }}" {{ request('status') === $k ? 'selected' : '' }}>{{ $v }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="card border-0 shadow-sm" style="border-radius:16px"><div class="card-body">
        <table class="table align-middle">
            <thead><tr><th>{{ translate('الكود') }}</th><th>{{ translate('الطالب') }}</th><th>{{ translate('المبلغ') }}</th><th>{{ translate('الحالة') }}</th><th>{{ translate('التاريخ') }}</th></tr></thead>
            <tbody>
            @forelse($requests as $r)
                <tr>
                    <td><code>{{ $r->short_code }}</code></td>
                    <td>{{ $r->requester?->f_name }} {{ $r->requester?->l_name }}<br><small class="text-muted">{{ $r->requester?->phone }}</small></td>
                    <td class="fw-bold">{{ number_format((float) $r->amount, 0) }}</td>
                    <td>{{ $r->status }}</td>
                    <td><small>{{ $r->created_at?->format('Y-m-d H:i') }}</small></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">{{ translate('لا طلبات') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $requests->links() }}
    </div></div>
</div>
@endsection
