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

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    @php
        $statusLabels = ['pending' => 'بانتظار الموافقة', 'paid' => 'مدفوع', 'declined' => 'مرفوض', 'cancelled' => 'ملغى', 'expired' => 'منتهٍ'];
        $statusClasses = ['pending' => 'warning', 'paid' => 'success', 'declined' => 'danger', 'cancelled' => 'secondary', 'expired' => 'dark'];
        $methodLabels = ['direct' => 'مباشر للعميل', 'link' => 'رابط', 'qr' => 'QR'];
        $expiredCount = isset($summary->expired_count)
            ? (int) $summary->expired_count
            : (int) \App\Models\PaymentRequest::where(function ($q) {
                $q->where('status', 'expired')
                    ->orWhere(function ($p) {
                        $p->where('status', 'pending')
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', now());
                    });
            })->count();
        $hasAdminCancelRoute = \Illuminate\Support\Facades\Route::has('admin.amial.surface.payment-requests.cancel');
    @endphp

    <div class="row g-3 mb-3">
        @foreach([
            ['كل الطلبات', (int) ($summary->total ?? 0), '#053391'],
            ['بانتظار الموافقة', (int) ($summary->pending_count ?? 0), '#A66A00'],
            ['طلبات مدفوعة', (int) ($summary->paid_count ?? 0), '#16794A'],
            ['طلبات مرفوضة', (int) ($summary->declined_count ?? 0), '#C62828'],
            ['طلبات منتهية', $expiredCount, '#495057'],
            ['إجمالي المدفوع', number_format((float) ($summary->paid_amount ?? 0), 2) . ' ر.ي', '#1D4FB8'],
        ] as [$label, $value, $color])
            <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100" style="border-radius:14px">
                <div class="card-body"><small class="text-muted">{{ translate($label) }}</small><div class="fs-5 fw-bold mt-1" style="color:{{ $color }}">{{ $value }}</div></div>
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
                <th>{{ translate('الطلب') }}</th><th>{{ translate('الطالب') }}</th><th>{{ translate('المطلوب منه / الدافع') }}</th>
                <th>{{ translate('الطريقة') }}</th><th>{{ translate('المبلغ') }}</th><th>{{ translate('الحالة') }}</th>
                <th>{{ translate('العملية/الوقت') }}</th><th>{{ translate('تفاصيل') }}</th>
            </tr></thead>
            <tbody>
            @forelse($requests as $r)
                @php
                    $recipientName = trim(($r->recipient?->f_name ?? '') . ' ' . ($r->recipient?->l_name ?? '')) ?: $r->recipient_name;
                    $recipientPhone = $r->recipient?->phone ?? $r->recipient_phone;
                    $paidByName = trim(($r->paidBy?->f_name ?? '') . ' ' . ($r->paidBy?->l_name ?? ''));
                    $effectiveStatus = ($r->status === 'pending' && $r->expires_at && $r->expires_at->isPast()) ? 'expired' : $r->status;
                @endphp
                <tr>
                    <td><code>{{ $r->short_code }}</code><br><small class="text-muted" title="{{ $r->request_ulid }}">#{{ $r->id }}</small></td>
                    <td><b>{{ $r->requester?->f_name }} {{ $r->requester?->l_name }}</b><br><small class="text-muted" dir="ltr">{{ $r->requester?->phone }}</small></td>
                    <td>
                        @if($recipientName || $recipientPhone)
                            <b>{{ $recipientName ?: '—' }}</b>
                            <br><small class="text-muted" dir="ltr">{{ $recipientPhone ?: '—' }}</small>
                        @elseif($r->paidBy)
                            <b>{{ $paidByName ?: '—' }}</b>
                            <span class="badge bg-info text-dark ms-1">{{ translate('الدافع الفعلي') }}</span>
                            <br><small class="text-muted" dir="ltr">{{ $r->paidBy->phone ?? '—' }}</small>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td><span class="badge bg-light text-dark">{{ translate($methodLabels[$r->share_method] ?? $r->share_method) }}</span></td>
                    <td class="fw-bold text-nowrap">{{ number_format((float) $r->amount, 2) }} ر.ي@if($r->note)<br><small class="text-muted fw-normal" title="{{ $r->note }}">{{ \Illuminate\Support\Str::limit($r->note, 34) }}</small>@endif</td>
                    <td><span class="badge bg-{{ $statusClasses[$effectiveStatus] ?? 'secondary' }}">{{ translate($statusLabels[$effectiveStatus] ?? $effectiveStatus) }}</span></td>
                    <td>
                        @if($r->paid_transaction_id)<code class="small" title="{{ $r->paid_transaction_id }}">{{ \Illuminate\Support\Str::limit($r->paid_transaction_id, 12) }}</code><br>@endif
                        <small>{{ ($r->paid_at ?? $r->created_at)?->format('Y-m-d H:i') }}</small>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#paymentRequestModal-{{ $r->id }}">
                            {{ translate('عرض') }}
                        </button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-5">{{ translate('لا توجد طلبات تطابق هذه الفلاتر') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">{{ translate('القرارات المالية يتخذها طرفا الطلب؛ يمكن للإدارة الإلغاء الإداري للطلبات المعلّقة مع توثيق السبب.') }}</small>
            {{ $requests->links() }}
        </div>
    </div></div>

    @foreach($requests as $r)
        @php
            $recipientName = trim(($r->recipient?->f_name ?? '') . ' ' . ($r->recipient?->l_name ?? '')) ?: $r->recipient_name;
            $recipientPhone = $r->recipient?->phone ?? $r->recipient_phone;
            $paidByName = trim(($r->paidBy?->f_name ?? '') . ' ' . ($r->paidBy?->l_name ?? ''));
            $effectiveStatus = ($r->status === 'pending' && $r->expires_at && $r->expires_at->isPast()) ? 'expired' : $r->status;
        @endphp
        <div class="modal fade" id="paymentRequestModal-{{ $r->id }}" tabindex="-1" aria-labelledby="paymentRequestModalLabel-{{ $r->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" style="border-radius:16px">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="paymentRequestModalLabel-{{ $r->id }}">
                            {{ translate('تفاصيل طلب المال') }} #{{ $r->id }} — <code>{{ $r->short_code }}</code>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ translate('إغلاق') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 small">
                            <div class="col-md-6">
                                <div class="text-muted">{{ translate('معرّف الطلب (ULID)') }}</div>
                                <code class="d-block mt-1">{{ $r->request_ulid }}</code>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted">{{ translate('المبلغ') }}</div>
                                <div class="fw-bold mt-1">{{ number_format((float) $r->amount, 2) }} ر.ي</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted">{{ translate('الحالة') }}</div>
                                <div class="mt-1"><span class="badge bg-{{ $statusClasses[$effectiveStatus] ?? 'secondary' }}">{{ translate($statusLabels[$effectiveStatus] ?? $effectiveStatus) }}</span></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted">{{ translate('الطالب') }}</div>
                                <div class="fw-semibold mt-1">{{ trim(($r->requester?->f_name ?? '') . ' ' . ($r->requester?->l_name ?? '')) ?: '—' }}</div>
                                <div class="text-muted" dir="ltr">{{ $r->requester?->phone ?? '—' }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted">{{ translate('المطلوب منه') }}</div>
                                <div class="fw-semibold mt-1">{{ $recipientName ?: '—' }}</div>
                                <div class="text-muted" dir="ltr">{{ $recipientPhone ?: '—' }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted">{{ translate('الدافع الفعلي') }}</div>
                                @if($r->paidBy)
                                    <div class="fw-semibold mt-1">{{ $paidByName ?: '—' }}</div>
                                    <div class="text-muted" dir="ltr">{{ $r->paidBy->phone ?? '—' }}</div>
                                @else
                                    <div class="text-muted mt-1">—</div>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted">{{ translate('طريقة الإيصال') }}</div>
                                <div class="mt-1">{{ translate($methodLabels[$r->share_method] ?? $r->share_method) }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted">{{ translate('تاريخ الإنشاء') }}</div>
                                <div class="mt-1" dir="ltr">{{ $r->created_at?->format('Y-m-d H:i') ?? '—' }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted">{{ translate('تاريخ الانتهاء') }}</div>
                                <div class="mt-1" dir="ltr">{{ $r->expires_at?->format('Y-m-d H:i') ?? '—' }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted">{{ translate('تاريخ الدفع') }}</div>
                                <div class="mt-1" dir="ltr">{{ $r->paid_at?->format('Y-m-d H:i') ?? '—' }}</div>
                            </div>
                            @if($r->paid_transaction_id)
                                <div class="col-12">
                                    <div class="text-muted">{{ translate('رقم العملية') }}</div>
                                    <code class="d-block mt-1">{{ $r->paid_transaction_id }}</code>
                                </div>
                            @endif
                            @if($r->note)
                                <div class="col-12">
                                    <div class="text-muted">{{ translate('الملاحظة') }}</div>
                                    <div class="mt-1">{{ $r->note }}</div>
                                </div>
                            @endif
                        </div>

                        @if($r->status === 'pending' && $hasAdminCancelRoute)
                            <hr class="my-3">
                            <form method="POST" action="{{ route('admin.amial.surface.payment-requests.cancel', $r->id) }}">
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold text-danger" for="cancel-reason-{{ $r->id }}">{{ translate('إلغاء إداري للطلب المعلّق') }}</label>
                                    <input type="text" id="cancel-reason-{{ $r->id }}" name="reason" class="form-control form-control-sm" required minlength="5" maxlength="500" placeholder="{{ translate('أدخل سبب الإلغاء الإداري (5 أحرف على الأقل)') }}">
                                </div>
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('{{ translate('هل أنت متأكد من إلغاء هذا الطلب إدارياً؟') }}')">
                                    {{ translate('تأكيد الإلغاء الإداري') }}
                                </button>
                            </form>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">{{ translate('إغلاق') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
