@extends('layouts.admin.app')
@section('title', translate('مركز مزوّدي الفواتير'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-2"><div><h4 class="fw-bold mb-1" style="color:#053391">⚡ {{ translate('مركز مزوّدي دفع الفواتير') }}</h4><small class="text-muted">{{ translate('طلبات اليوم') }}: {{ $ordersToday }} — {{ translate('الأرصدة المعروضة هي آخر قراءة مؤكدة فقط') }}</small></div></div>
    @if(session('success'))<div class="alert alert-success mt-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger mt-2">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger mt-2">{{ $errors->first() }}</div>@endif
    @forelse($providers as $p)
        @php($ready = $p->isReadyForPayments())
        <div class="card border-0 shadow-sm mt-3" style="border-radius:16px"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap"><div><h5 class="mb-1">{{ $p->display_name_ar ?? $p->name }}</h5><small class="text-muted">{{ $p->code }} · {{ $p->integration_type }} · {{ $p->zone_code }}</small></div><div>@if($p->is_active)<span class="badge bg-success">مفعّل</span>@else<span class="badge bg-secondary">معطّل</span>@endif <span class="badge {{ $ready ? 'bg-success' : 'bg-warning text-dark' }}">{{ $p->integration_status }}</span></div></div>
            <div class="row g-2 mt-2 small"><div class="col-md-2"><div class="text-muted">الرصيد المؤكد</div><b>{{ $p->last_known_balance !== null ? number_format((float)$p->last_known_balance, 2).' '.($p->balance_currency ?? '') : 'غير معروف' }}</b></div><div class="col-md-2"><div class="text-muted">طلبات اليوم</div><b>{{ $p->today_orders_count }}</b></div><div class="col-md-2"><div class="text-muted">معلّقة</div><b>{{ $p->pending_orders_count }}</b></div><div class="col-md-2"><div class="text-muted">ناجحة</div><b>{{ $p->successful_orders_count }}</b></div><div class="col-md-2"><div class="text-muted">فاشلة</div><b>{{ $p->failed_orders_count }}</b></div><div class="col-md-2"><div class="text-muted">الخدمات</div><b>{{ $p->services->count() }}</b></div></div>
            @if($p->last_health_message)<div class="alert alert-light mt-3 mb-0 small">{{ $p->last_health_message }}</div>@endif
            @if($canConfigure)<div class="row g-3 mt-1"><div class="col-lg-5"><form method="POST" action="{{ route('admin.amial.surface.bill-providers.configure', $p->id) }}" class="border rounded p-3 h-100">@csrf<h6>إعداد الاعتماد (لا تُعرض القيم المحفوظة)</h6><input class="form-control form-control-sm mb-2" name="endpoint_url" value="{{ old('endpoint_url', $p->endpoint_url) }}" placeholder="Endpoint HTTPS"><input class="form-control form-control-sm mb-2" name="username" placeholder="UserName"><input class="form-control form-control-sm mb-2" name="account_number" placeholder="AccountNumber"><input class="form-control form-control-sm mb-2" name="password" type="password" placeholder="Password (اتركه فارغاً للإبقاء)"><input class="form-control form-control-sm mb-2" name="api_token" type="password" placeholder="API Token (اتركه فارغاً للإبقاء)"><input class="form-control form-control-sm mb-2" name="webhook_secret" type="password" placeholder="Webhook secret (اتركه فارغاً للإبقاء)"><input class="form-control form-control-sm mb-2" name="timeout_seconds" type="number" min="3" max="60" value="{{ old('timeout_seconds', data_get($p->config, 'timeout_seconds', 15)) }}" placeholder="Timeout"><input class="form-control form-control-sm mb-2" required name="reason" placeholder="سبب التغيير (10 أحرف على الأقل)"><button class="btn btn-primary btn-sm">حفظ الإعداد وإيقاف المزود حتى الفحص</button></form></div><div class="col-lg-7"><div class="border rounded p-3 mb-3"><div class="d-flex justify-content-between"><h6>اختبار الجاهزية</h6><form method="POST" action="{{ route('admin.amial.surface.bill-providers.refresh', $p->id) }}">@csrf<button class="btn btn-outline-primary btn-sm">اختبار الرصيد الآن</button></form></div><small class="text-muted">آخر فحص: {{ $p->balance_checked_at?->format('Y-m-d H:i:s') ?? 'لم يُجرَ' }} · الفشل المتتالي: {{ $p->failure_streak }}</small></div><form method="POST" action="{{ route('admin.amial.surface.bill-providers.toggle', $p->id) }}" class="border rounded p-3">@csrf<h6>{{ $p->is_active ? 'تعطيل المزود' : 'تفعيل المزود' }}</h6><div class="input-group"><input class="form-control form-control-sm" required name="reason" placeholder="سبب الإجراء (10 أحرف على الأقل)"><button class="btn btn-sm {{ $p->is_active ? 'btn-outline-danger' : 'btn-success' }}">{{ $p->is_active ? 'تعطيل' : 'تفعيل' }}</button></div></form></div></div>@endif
            @if($p->integration_type === 'free_sadad' && $canConfigure)<div class="mt-3"><h6>توجيه خدمات Free Sadad</h6>@foreach($p->services as $service)@include('admin-views.amial.surface.partials.bill-service-routing-form', ['provider' => $p, 'service' => $service])@endforeach @include('admin-views.amial.surface.partials.bill-service-routing-form', ['provider' => $p, 'service' => null])</div>@endif
        </div></div>
    @empty <div class="alert alert-info mt-3">لا مزوّدون بعد.</div> @endforelse

    <div class="alert alert-warning mt-4 mb-3">
        <strong>قاعدة مالية:</strong>
        العكس التلقائي لعملية ناجحة غير مفعّل في تكامل Free Sadad الحالي.
        لا يُعاد رصيد العميل بعد نجاح المزود إلا بعد وجود تأكيد عكس موثّق من المزود ومسار مالي معتمد.
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:16px">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-1">آخر عمليات السداد</h5>
                    <small class="text-muted">آخر 50 عملية — للمراقبة والتسوية، بلا كشف بيانات اعتماد المزود.</small>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>مرجع أميال</th><th>العميل</th><th>الخدمة</th><th>المبلغ</th>
                        <th>الحالة</th><th>مرجع المزود</th><th>المحاولات</th><th>آخر تحديث</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($recentOrders as $o)
                        @php
                            $statusClass = match($o->status) {
                                'success' => 'bg-success',
                                'failed', 'reversed' => 'bg-danger',
                                'pending_provider_confirmation', 'processing', 'pending' => 'bg-warning text-dark',
                                default => 'bg-secondary',
                            };
                            $phone = (string) ($o->user?->phone ?? '');
                            $maskedPhone = strlen($phone) > 6
                                ? substr($phone, 0, 4).'***'.substr($phone, -3)
                                : '—';
                        @endphp
                        <tr>
                            <td><code>{{ $o->order_ulid }}</code></td>
                            <td>{{ trim(($o->user?->f_name ?? '').' '.($o->user?->l_name ?? '')) ?: '—' }}<br><small class="text-muted">{{ $maskedPhone }}</small></td>
                            <td>{{ $o->service?->display_name_ar ?? $o->service?->name ?? '—' }}<br><small class="text-muted">{{ $o->provider?->display_name_ar ?? $o->provider?->name ?? '—' }}</small></td>
                            <td>{{ number_format((float)$o->amount, 2) }} ر.ي<br><small class="text-muted">رسوم {{ number_format((float)$o->fee, 2) }}</small></td>
                            <td><span class="badge {{ $statusClass }}">{{ $o->status }}</span>
                                @if($o->status === 'pending_provider_confirmation' && $o->next_reconciliation_at)
                                    <br><small class="text-muted">الفحص: {{ $o->next_reconciliation_at->format('Y-m-d H:i:s') }}</small>
                                @endif
                            </td>
                            <td><code>{{ $o->provider_reference ?: '—' }}</code>
                                @if($o->provider_message)<br><small class="text-muted">{{ IlluminateSupportStr::limit($o->provider_message, 80) }}</small>@endif
                            </td>
                            <td>{{ $o->provider_attempt_count ?? 0 }}</td>
                            <td>{{ $o->updated_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">لا توجد عمليات سداد بعد.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
