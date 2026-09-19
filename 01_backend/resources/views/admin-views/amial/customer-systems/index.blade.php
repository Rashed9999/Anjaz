@extends('layouts.admin.app')

@section('title', 'مركز أنظمة العميل')

@section('content')
<div class="container-fluid px-0" dir="rtl" data-testid="customer-systems-center">
    @php
        $s = $snapshot['summary'] ?? [];
        $systems = $snapshot['systems'] ?? [];
        $stateClass = fn ($state) => match($state) {
            'complete' => 'success',
            'partial' => 'warning',
            'deferred' => 'secondary',
            default => 'danger',
        };
    @endphp

    <div class="card border-0 shadow-sm mb-4" style="border-radius:18px">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div>
                    <h3 class="mb-1">مركز أنظمة العميل</h3>
                    <div class="text-muted">
                        رؤية تشغيلية موحدة لما يحدث في طبقة العميل — من المصادر الفعلية، بلا أرقام تجميلية.
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('admin.amial.customer-systems.index') }}" class="btn btn-outline-primary">
                        ↻ تحديث
                    </a>
                    <a href="{{ route('admin.amial.audit.index') }}" class="btn btn-outline-dark">
                        سجل التدقيق
                    </a>
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-0">
                هذا المركز <strong>لا يصبح مصدراً مالياً ثانياً</strong>. أي إجراء تحكم يفتح الشاشة الأصلية للنظام
                ويخضع لصلاحياتها وحراسها وسجلها.
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach([
            ['العملاء', $s['customers'] ?? 0, 'tio-users-switch'],
            ['KYC معلّق', $s['kyc_pending'] ?? 0, 'tio-user-add'],
            ['منع سياسات / 24س', $s['policy_blocks_24h'] ?? 0, 'tio-security-check'],
            ['استثناءات حدود', $s['limit_overrides'] ?? 0, 'tio-tune'],
            ['حسابات أجل مستحقة', $s['outstanding_credit_accounts'] ?? 0, 'tio-money-vs'],
            ['طلبات أموال معلقة', $s['pending_payment_requests'] ?? 0, 'tio-send'],
            ['سداد معلّق', $s['pending_bill_orders'] ?? 0, 'tio-time'],
            ['PDF إيصالات فاشل', $s['receipt_pdf_failures'] ?? 0, 'tio-document-text'],
        ] as $kpi)
            <div class="col-6 col-lg-3">
                <div class="card h-100 border-0 shadow-sm" style="border-radius:14px">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2">
                            <i class="{{ $kpi[2] }} fs-3 text-primary"></i>
                            <div>
                                <div class="text-muted small">{{ $kpi[0] }}</div>
                                <div class="fs-4 fw-bold">{{ number_format((float)$kpi[1], 0) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="#systems">حالة الأنظمة</a>
        <a class="btn btn-sm btn-outline-secondary" href="#policy-blocks">الحركات المرفوضة</a>
        <a class="btn btn-sm btn-outline-secondary" href="#credits">الأجل والديون</a>
        <a class="btn btn-sm btn-outline-secondary" href="#receipts">الإيصالات</a>
        <a class="btn btn-sm btn-outline-secondary" href="#bill-pay">السداد</a>
        <a class="btn btn-sm btn-outline-secondary" href="#payment-requests">طلبات الأموال</a>
        <a class="btn btn-sm btn-outline-secondary" href="#notifications">الإشعارات</a>
        <a class="btn btn-sm btn-outline-secondary" href="#push-delivery">تسليم Push</a>
    </div>

    <div id="systems" class="row g-3 mb-5">
        @foreach($systems as $system)
            <div class="col-12 col-xl-6">
                <div class="card h-100 border-0 shadow-sm" style="border-radius:16px">
                    <div class="card-body">
                        <div class="d-flex gap-2 justify-content-between align-items-start">
                            <div>
                                <h5 class="mb-1">{{ $system['title'] }}</h5>
                                <div class="text-muted small">{{ $system['description'] }}</div>
                            </div>
                            <span class="badge bg-{{ $stateClass($system['state']) }}">
                                {{ $system['state_label'] }}
                            </span>
                        </div>

                        <div class="row g-2 mt-2">
                            <div class="col-md-4">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted mb-1">الرؤية</div>
                                    <div class="small fw-semibold">{{ $system['visibility'] ?? '—' }}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted mb-1">الأوامر</div>
                                    <div class="small fw-semibold">{{ $system['controls'] ?? '—' }}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted mb-1">الأثر</div>
                                    <div class="small fw-semibold">{{ $system['audit'] ?? '—' }}</div>
                                </div>
                            </div>
                        </div>

                        @if(!empty($system['metrics']))
                            <div class="row g-2 mt-2">
                                @foreach($system['metrics'] as $metric)
                                    <div class="col-6">
                                        <div class="border rounded p-2 bg-light">
                                            <div class="small text-muted">{{ $metric['label'] }}</div>
                                            <strong>
                                                @if(!empty($metric['money']))
                                                    {{ number_format((float)$metric['value'], 2) }} ر.ي
                                                @else
                                                    {{ is_numeric($metric['value']) ? number_format((float)$metric['value'], 0) : $metric['value'] }}
                                                @endif
                                            </strong>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if(!empty($system['gap']))
                            <div class="alert alert-warning py-2 px-3 mt-3 mb-2 small">
                                <strong>فجوة معلنة:</strong> {{ $system['gap'] }}
                            </div>
                        @endif

                        @if(!empty($system['actions']))
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                @foreach($system['actions'] as $action)
                                    <a class="btn btn-sm btn-outline-primary" href="{{ $action['url'] }}">
                                        {{ $action['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div id="policy-blocks" class="card border-0 shadow-sm mb-4" style="border-radius:16px">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">الحركات المرفوضة بواسطة الحراس</h5>
                <small class="text-muted">آخر 40 منع مسجّل — لا يوجد زر لتعطيل الحارس.</small>
            </div>
            <a href="{{ route('admin.amial.audit.index', ['action' => 'CUSTOMER_POLICY_BLOCKED']) }}"
               class="btn btn-sm btn-outline-dark">فتح التدقيق</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>الوقت</th><th>العميل</th><th>الميزة</th><th>المستوى</th>
                    <th>المبلغ</th><th>السبب</th><th>المرجع</th>
                </tr></thead>
                <tbody>
                @forelse($snapshot['policy_blocks'] ?? [] as $row)
                    <tr>
                        <td class="text-nowrap">{{ $row['at'] }}</td>
                        <td>
                            @if($row['customer_id'])
                                <a href="{{ route('admin.amial.customer.page') }}?open={{ urlencode((string)$row['customer_id']) }}">
                                    #{{ $row['customer_id'] }}
                                </a>
                            @else — @endif
                        </td>
                        <td><code>{{ $row['feature'] }}</code></td>
                        <td>{{ $row['tier'] ?? '—' }}</td>
                        <td>{{ $row['amount'] !== null ? number_format((float)$row['amount'], 2).' ر.ي' : '—' }}</td>
                        <td class="small">{{ $row['reason'] ?: $row['code'] }}</td>
                        <td class="font-monospace small">{{ $row['transaction_id'] ?: $row['decision_id'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">لا توجد حالات منع مسجلة بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div id="credits" class="card border-0 shadow-sm mb-4" style="border-radius:16px">
        <div class="card-header bg-white">
            <h5 class="mb-1">الأجل والديون — أعلى الأرصدة المستحقة</h5>
            <small class="text-muted">قراءة مباشرة من customer_credit_accounts.</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>الحساب</th><th>العميل</th><th>التاجر</th><th>الرصيد</th><th>الحد</th><th>التصنيف</th><th>آخر سداد</th></tr></thead>
                <tbody>
                @forelse($snapshot['credits'] ?? [] as $row)
                    <tr>
                        <td>#{{ $row['id'] }}</td>
                        <td>
                            {{ $row['customer_name'] }}
                            @if($row['customer_user_id'])
                                <a class="ms-1" href="{{ route('admin.amial.customer.page') }}?open={{ $row['customer_user_id'] }}">
                                    (#{{ $row['customer_user_id'] }})
                                </a>
                            @endif
                        </td>
                        <td>#{{ $row['merchant_user_id'] }}</td>
                        <td class="fw-bold">{{ number_format((float)$row['balance'], 2) }} ر.ي</td>
                        <td>{{ (float)$row['limit'] > 0 ? number_format((float)$row['limit'], 2).' ر.ي' : 'بلا حد' }}</td>
                        <td>{{ $row['classification'] }}</td>
                        <td>{{ $row['last_payment_at'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">لا توجد أرصدة أجل مستحقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div id="receipts" class="card border-0 shadow-sm mb-4" style="border-radius:16px">
        <div class="card-header bg-white">
            <h5 class="mb-1">آخر الإيصالات</h5>
            <small class="text-muted">النوع، الحالة، العملية المرجعية وحالة المستند.</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>السند</th><th>العميل</th><th>النوع</th><th>المبلغ</th><th>الرسوم</th><th>PDF</th><th>مرجع العملية</th><th>الإصدار</th></tr></thead>
                <tbody>
                @forelse($snapshot['receipts'] ?? [] as $row)
                    <tr>
                        <td class="font-monospace">{{ $row['receipt_number'] }}</td>
                        <td><a href="{{ route('admin.amial.customer.page') }}?open={{ $row['user_id'] }}">#{{ $row['user_id'] }}</a></td>
                        <td>{{ $row['receipt_type'] }}</td>
                        <td>{{ number_format((float)$row['amount'], 2) }} ر.ي</td>
                        <td>{{ number_format((float)$row['fee'], 2) }} ر.ي</td>
                        <td><span class="badge bg-{{ $row['status'] === 'pdf_generated' ? 'success' : ($row['status'] === 'pdf_failed' ? 'danger' : 'warning') }}">{{ $row['status'] }}</span></td>
                        <td class="font-monospace small">{{ $row['reference_transaction_id'] }}</td>
                        <td>{{ $row['issued_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">لا توجد إيصالات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div id="bill-pay" class="card border-0 shadow-sm mb-4" style="border-radius:16px">
        <div class="card-header bg-white d-flex justify-content-between">
            <div><h5 class="mb-1">عمليات السداد التي تحتاج متابعة</h5><small class="text-muted">Pending / Processing / Pending Provider Confirmation / Failed.</small></div>
            <a href="{{ route('admin.amial.surface.bill-providers') }}" class="btn btn-sm btn-outline-primary">مركز السداد</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>مرجع أميال</th><th>العميل</th><th>المبلغ</th><th>الرسوم</th><th>الحالة</th><th>مرجع المزود</th><th>آخر رسالة</th><th>تحديث</th></tr></thead>
                <tbody>
                @forelse($snapshot['bill_pay'] ?? [] as $row)
                    <tr>
                        <td class="font-monospace small">{{ $row['order_ulid'] }}</td>
                        <td><a href="{{ route('admin.amial.customer.page') }}?open={{ $row['user_id'] }}">#{{ $row['user_id'] }}</a></td>
                        <td>{{ number_format((float)$row['amount'], 2) }} ر.ي</td>
                        <td>{{ number_format((float)$row['fee'], 2) }} ر.ي</td>
                        <td><span class="badge bg-{{ $row['status'] === 'failed' ? 'danger' : 'warning' }}">{{ $row['status'] }}</span></td>
                        <td class="font-monospace small">{{ $row['provider_reference'] ?: '—' }}</td>
                        <td class="small">{{ IlluminateSupportStr::limit((string)$row['provider_message'], 90) }}</td>
                        <td>{{ $row['updated_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">لا توجد عمليات سداد تحتاج متابعة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4" style="border-radius:16px" id="provider-requests">
        <div class="card-header bg-white d-flex justify-content-between">
            <div>
                <h5 class="mb-1">سجل اتصال مزوّدي السداد</h5>
                <small class="text-muted">أثر الاتصال فقط — لا تُعرض request/response payloads الحساسة.</small>
            </div>
            <a href="{{ route('admin.amial.surface.bill-providers') }}" class="btn btn-sm btn-outline-primary">مركز السداد</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>مرجع أميال</th><th>الطلب</th><th>HTTP</th><th>الزمن</th><th>النتيجة</th><th>الخطأ</th><th>الوقت</th></tr></thead>
                <tbody>
                @forelse($snapshot['bill_provider_requests'] ?? [] as $row)
                    <tr>
                        <td class="font-monospace small">{{ $row['order_ulid'] ?: '—' }}</td>
                        <td>{{ $row['request_type'] }}</td>
                        <td>{{ $row['http_status'] ?? '—' }}</td>
                        <td>{{ $row['latency_ms'] !== null ? $row['latency_ms'].' ms' : '—' }}</td>
                        <td>
                            <span class="badge bg-{{ $row['was_successful'] ? 'success' : 'danger' }}">
                                {{ $row['was_successful'] ? 'نجح الاتصال' : 'فشل الاتصال' }}
                            </span>
                        </td>
                        <td class="small">{{ IlluminateSupportStr::limit((string)($row['error_message'] ?? ''), 100) ?: '—' }}</td>
                        <td>{{ $row['created_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">لا توجد اتصالات مزوّد مسجلة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div id="payment-requests" class="card border-0 shadow-sm mb-4" style="border-radius:16px">
        <div class="card-header bg-white d-flex justify-content-between">
            <div><h5 class="mb-1">آخر طلبات الأموال</h5><small class="text-muted">من المصدر التشغيلي نفسه، لا نسخة للإدارة.</small></div>
            <a href="{{ route('admin.amial.surface.payment-requests') }}" class="btn btn-sm btn-outline-primary">اللوحة الكاملة</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>المرجع</th><th>الطالب</th><th>المستلم</th><th>المبلغ</th><th>الحالة</th><th>عملية الدفع</th><th>الإنشاء</th></tr></thead>
                <tbody>
                @forelse($snapshot['payment_requests'] ?? [] as $row)
                    <tr>
                        <td class="font-monospace small">{{ $row['request_ulid'] }}</td>
                        <td>#{{ $row['requester_user_id'] }}</td>
                        <td>{{ $row['recipient_user_id'] ? '#'.$row['recipient_user_id'] : 'عام/رقم هاتف' }}</td>
                        <td>{{ number_format((float)$row['amount'], 2) }} ر.ي</td>
                        <td>{{ $row['status'] }}</td>
                        <td class="font-monospace small">{{ $row['paid_transaction_id'] ?: '—' }}</td>
                        <td>{{ $row['created_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">لا توجد طلبات أموال.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div id="notifications" class="card border-0 shadow-sm mb-4" style="border-radius:16px">
        <div class="card-header bg-white">
            <h5 class="mb-1">آخر إشعارات العملاء</h5>
            <small class="text-muted">«موجود في قاعدة الإشعارات» لا يعني بالضرورة أن Push وصل للجهاز؛ الفجوة تظهر أعلاه بصراحة.</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>العميل</th><th>النوع</th><th>العنوان</th><th>القراءة</th><th>الإنشاء</th></tr></thead>
                <tbody>
                @forelse($snapshot['notifications'] ?? [] as $row)
                    <tr>
                        <td><a href="{{ route('admin.amial.customer.page') }}?open={{ $row['user_id'] }}">#{{ $row['user_id'] }}</a></td>
                        <td><code>{{ $row['type'] }}</code></td>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['read_at'] ? 'مقروء' : 'غير مقروء' }}</td>
                        <td>{{ $row['created_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">لا توجد إشعارات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div id="push-delivery" class="card border-0 shadow-sm mb-4" style="border-radius:16px">
        <div class="card-header bg-white">
            <h5 class="mb-1">سجل إرسال Push الخارجي</h5>
            <small class="text-muted">يسجل نتيجة إرسال FCM دون حفظ token أو payload. حالة provider_accepted تعني أن FCM قبل الرسالة، لا أنها عُرضت حتماً على الجهاز.</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>الوقت</th><th>العميل</th><th>النوع</th><th>المرجع</th><th>الحالة</th><th>المحاولة</th><th>HTTP</th><th>مرجع FCM</th><th>الخطأ</th></tr></thead>
                <tbody>
                @forelse($snapshot['notification_deliveries'] ?? [] as $row)
                    <tr>
                        <td class="text-nowrap">{{ $row['created_at'] }}</td>
                        <td>
                            @if($row['user_id'])
                                <a href="{{ route('admin.amial.customer.page') }}?open={{ $row['user_id'] }}">#{{ $row['user_id'] }}</a>
                            @else — @endif
                        </td>
                        <td><code>{{ $row['notification_type'] ?: '—' }}</code></td>
                        <td class="font-monospace small">{{ $row['transaction_id'] ?: '—' }}</td>
                        <td>
                            <span class="badge bg-{{ $row['status'] === 'provider_accepted' ? 'success' : ($row['status'] === 'skipped' ? 'secondary' : 'danger') }}">
                                {{ $row['status'] }}
                            </span>
                        </td>
                        <td>{{ $row['attempt'] }}</td>
                        <td>{{ $row['http_status'] ?? '—' }}</td>
                        <td class="font-monospace small">{{ $row['provider_message_id'] ?: '—' }}</td>
                        <td class="small">{{ $row['error_code'] ?: '—' }}{{ $row['error_message'] ? ' — '.IlluminateSupportStr::limit((string)$row['error_message'], 90) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">لا توجد نتائج إرسال Push مسجلة بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="text-muted small mb-4">
        آخر تحديث للّقطة: {{ $snapshot['generated_at'] ?? '—' }}
    </div>
</div>
@endsection
