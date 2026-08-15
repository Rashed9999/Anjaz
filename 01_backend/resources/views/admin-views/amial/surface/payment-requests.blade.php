@extends('layouts.admin.app')
@section('title', translate('طلبات الأموال'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1" style="color:var(--amial-primary)">📨 {{ translate('طلبات الأموال') }}</h4>
            <small class="text-muted">{{ translate('متابعة الطلب المباشر من إنشائه حتى موافقة المستلم وترحيل العملية') }}</small>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.amial.surface.payment-requests', request()->except('page')) }}" aria-label="{{ translate('تحديث البيانات') }}">↻ {{ translate('تحديث') }}</a>
            <a class="btn btn-sm btn-success" href="{{ route('admin.amial.surface.payment-requests', array_merge(request()->except('page'), ['export' => 'csv'])) }}">{{ translate('تصدير CSV') }}</a>
        </div>
    </div>

    @php
        $statusLabels = ['pending' => 'بانتظار الموافقة', 'paid' => 'مدفوع', 'declined' => 'مرفوض', 'cancelled' => 'ملغى', 'expired' => 'منتهٍ'];
        $statusClasses = ['pending' => 'warning', 'paid' => 'success', 'declined' => 'danger', 'cancelled' => 'secondary', 'expired' => 'dark'];
        $methodLabels = ['direct' => 'مباشر للعميل', 'link' => 'رابط', 'qr' => 'QR'];
    @endphp

    <div class="row g-3 mb-3">
        @foreach([
            ['كل الطلبات', (int) ($summary->total ?? 0), '#053391'],
            ['بانتظار الموافقة', (int) ($summary->pending_count ?? 0), '#A66A00'],
            ['طلبات مدفوعة', (int) ($summary->paid_count ?? 0), '#16794A'],
            ['إجمالي المدفوع', number_format((float) ($summary->paid_amount ?? 0), 0) . ' ر.ي', '#1D4FB8'],
        ] as [$label, $value, $color])
            <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100" style="border-radius:14px">
                <div class="card-body"><small class="text-muted">{{ translate($label) }}</small><div class="fs-4 fw-bold mt-1" style="color:{{ $color }}">{{ $value }}</div></div>
            </div></div>
        @endforeach
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3" style="border-radius:16px" aria-label="{{ translate('فلاتر طلبات الأموال') }}">
        <div class="card-body"><div class="row g-2 align-items-end">
            <div class="col-12 col-lg-4">
                <label class="form-label small">{{ translate('بحث') }}</label>
                <input name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ translate('رقم الطلب، العملية، الاسم أو الهاتف') }}">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small">{{ translate('الحالة') }}</label>
                <select name="status" class="form-control">
                    <option value="">{{ translate('كل الحالات') }}</option>
                    @foreach($statusLabels as $key => $label)<option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ translate($label) }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small">{{ translate('طريقة الإيصال') }}</label>
                <select name="share_method" class="form-control">
                    <option value="">{{ translate('كل الطرق') }}</option>
                    @foreach($methodLabels as $key => $label)<option value="{{ $key }}" {{ request('share_method') === $key ? 'selected' : '' }}>{{ translate($label) }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2"><label class="form-label small">{{ translate('من تاريخ') }}</label><input type="date" name="from" value="{{ request('from') }}" class="form-control"></div>
            <div class="col-6 col-lg-2"><label class="form-label small">{{ translate('إلى تاريخ') }}</label><input type="date" name="to" value="{{ request('to') }}" class="form-control"></div>
            <div class="col-12 d-flex gap-2 mt-3">
                <button class="btn btn-primary">{{ translate('تطبيق الفلاتر') }}</button>
                <a class="btn btn-outline-secondary" href="{{ route('admin.amial.surface.payment-requests') }}">{{ translate('مسح') }}</a>
            </div>
        </div></div>
    </form>

    <div class="card border-0 shadow-sm" style="border-radius:16px"><div class="card-body p-0">
        <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ translate('الطلب') }}</th><th>{{ translate('الطالب') }}</th><th>{{ translate('المطلوب منه') }}</th>
                <th>{{ translate('الطريقة') }}</th><th>{{ translate('المبلغ') }}</th><th>{{ translate('الحالة') }}</th>
                <th>{{ translate('العملية/الوقت') }}</th>
            </tr></thead>
            <tbody>
            @forelse($requests as $r)
                <tr>
                    <td><code>{{ $r->short_code }}</code><br><small class="text-muted" title="{{ $r->request_ulid }}">#{{ $r->id }}</small></td>
                    <td><b>{{ $r->requester?->f_name }} {{ $r->requester?->l_name }}</b><br><small class="text-muted" dir="ltr">{{ $r->requester?->phone }}</small></td>
                    <td>
                        <b>{{ trim(($r->recipient?->f_name ?? '') . ' ' . ($r->recipient?->l_name ?? '')) ?: ($r->recipient_name ?: '—') }}</b>
                        <br><small class="text-muted" dir="ltr">{{ $r->recipient?->phone ?? $r->recipient_phone ?? '—' }}</small>
                    </td>
                    <td><span class="badge bg-light text-dark">{{ translate($methodLabels[$r->share_method] ?? $r->share_method) }}</span></td>
                    <td class="fw-bold text-nowrap">{{ number_format((float) $r->amount, 0) }} ر.ي@if($r->note)<br><small class="text-muted fw-normal" title="{{ $r->note }}">{{ \Illuminate\Support\Str::limit($r->note, 34) }}</small>@endif</td>
                    <td><span class="badge bg-{{ $statusClasses[$r->status] ?? 'secondary' }}">{{ translate($statusLabels[$r->status] ?? $r->status) }}</span></td>
                    <td>
                        @if($r->paid_transaction_id)<code class="small" title="{{ $r->paid_transaction_id }}">{{ \Illuminate\Support\Str::limit($r->paid_transaction_id, 12) }}</code><br>@endif
                        <small>{{ ($r->paid_at ?? $r->created_at)?->format('Y-m-d H:i') }}</small>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">{{ translate('لا توجد طلبات تطابق هذه الفلاتر') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">{{ translate('القرارات المالية يتخذها طرفا الطلب؛ هذه الشاشة للمتابعة والتدقيق فقط.') }}</small>
            {{ $requests->links() }}
        </div>
    </div></div>
</div>
@endsection
