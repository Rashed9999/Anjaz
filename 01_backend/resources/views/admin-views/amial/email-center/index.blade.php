@extends('layouts.admin.app')

@section('title', 'البريد والتحقق')

@push('css_or_js')
<style>
    .email-center .hero { background:linear-gradient(135deg,#053391 0%,#0b57d0 100%); color:#fff; border:0; overflow:hidden; }
    .email-center .hero .sub { color:rgba(255,255,255,.78); }
    .email-center .metric { border:1px solid #e7ebf3; border-radius:16px; background:#fff; height:100%; }
    .email-center .metric strong { font-size:1.45rem; display:block; }
    .email-center .tiny { font-size:.78rem; }
    .email-center .status-dot { width:9px; height:9px; border-radius:50%; display:inline-block; margin-inline-end:6px; }
    .email-center .table td,.email-center .table th { vertical-align:middle; white-space:nowrap; }
    .email-center .email-cell { direction:ltr; text-align:right; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
    .email-center .challenge-cell { direction:ltr; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.78rem; }
    .email-center .health-row { border-bottom:1px dashed #e6eaf2; padding:.65rem 0; }
    .email-center .health-row:last-child { border-bottom:0; }
    .email-center .filter-card { border-radius:16px; border:1px solid #e7ebf3; }
</style>
@endpush

@section('content')
@php
    $purposeLabels = [
        'registration' => 'إنشاء حساب',
        'password_reset' => 'استعادة كلمة المرور',
        'pin_recovery' => 'استعادة PIN',
        'email_change' => 'تغيير البريد',
    ];
    $statusLabels = [
        'pending' => ['قيد الإرسال','secondary'],
        'sent' => ['أُرسل','primary'],
        'delivered' => ['تم التسليم','success'],
        'delayed' => ['متأخر','warning'],
        'bounced' => ['مرتد','danger'],
        'complained' => ['شكوى Spam','danger'],
        'failed' => ['فشل','danger'],
    ];
@endphp

<div class="email-center">
    <div class="card hero shadow-sm mb-4">
        <div class="card-body p-4 p-lg-5 d-flex flex-column flex-lg-row justify-content-between gap-4">
            <div>
                <div class="small text-uppercase fw-semibold mb-2">Amial Mail Security Center</div>
                <h2 class="mb-2">✉️ مركز البريد والتحقق</h2>
                <p class="sub mb-0">مراقبة تسليم رسائل Resend، تحديات OTP، هوية البريد، والاستعادة — بدون كشف الرموز أو المفاتيح السرية.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-content-start">
                <a class="btn btn-light btn-sm" href="{{ route('admin.amial.email-center.export', request()->query()) }}">تصدير CSV مقنّع</a>
                @if(auth('user')->user()?->hasPlatformPermission('platform.settings.update'))
                    <a class="btn btn-outline-light btn-sm" href="{{ route('admin.amial.otp.page') }}">بوابات OTP الأخرى</a>
                @endif
            </div>
        </div>
    </div>

    <div class="alert alert-info border-0 shadow-sm mb-4">
        <strong>حماية الخصوصية:</strong>
        @if($revealPii)
            لديك صلاحية كشف PII، لذلك تظهر عناوين البريد كاملة في الشاشة ويُسجّل فتح هذه الصفحة في سجل التدقيق كعرض بيانات شخصية. التصدير يبقى مقنّعاً دائماً.
        @else
            عناوين البريد مقنّعة. كشف البريد الكامل يحتاج صلاحية مستقلة <code>platform.customers.pii.reveal</code>، أما رموز OTP وhash والمفاتيح فلا تظهر لأي موظف.
        @endif
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="metric p-3 shadow-sm"><span class="text-muted tiny">طلبات خلال 24 ساعة</span><strong>{{ number_format($stats['total_24h']) }}</strong><span class="text-muted tiny">كل أغراض التحقق</span></div></div>
        <div class="col-6 col-xl-3"><div class="metric p-3 shadow-sm"><span class="text-muted tiny">تم التسليم</span><strong>{{ number_format($stats['delivered_24h']) }}</strong><span class="text-success tiny">نسبة {{ number_format($stats['delivery_rate'], 1) }}%</span></div></div>
        <div class="col-6 col-xl-3"><div class="metric p-3 shadow-sm"><span class="text-muted tiny">تم التحقق</span><strong>{{ number_format($stats['verified_24h']) }}</strong><span class="text-muted tiny">OTP صحيح خلال 24 ساعة</span></div></div>
        <div class="col-6 col-xl-3"><div class="metric p-3 shadow-sm"><span class="text-muted tiny">مرتد / شكوى</span><strong>{{ number_format($stats['bounced_24h'] + $stats['complained_24h']) }}</strong><span class="text-danger tiny">يحتاج متابعة عند الارتفاع</span></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4"><h5 class="mb-0">هوية البريد في الحسابات</h5></div>
                <div class="card-body px-4">
                    <div class="row g-3">
                        <div class="col-6 col-md-3"><div class="p-3 rounded bg-light"><div class="tiny text-muted">بريد Canonical</div><div class="fs-4 fw-bold">{{ number_format($identity['canonical']) }}</div></div></div>
                        <div class="col-6 col-md-3"><div class="p-3 rounded bg-light"><div class="tiny text-muted">موثّق</div><div class="fs-4 fw-bold">{{ $identity['verified'] === null ? '—' : number_format($identity['verified']) }}</div></div></div>
                        <div class="col-6 col-md-3"><div class="p-3 rounded bg-light"><div class="tiny text-muted">بدون بريد</div><div class="fs-4 fw-bold {{ $identity['missing'] ? 'text-warning' : '' }}">{{ number_format($identity['missing']) }}</div></div></div>
                        <div class="col-6 col-md-3"><div class="p-3 rounded bg-light"><div class="tiny text-muted">تعارض قديم</div><div class="fs-4 fw-bold {{ $identity['conflicts'] ? 'text-danger' : '' }}">{{ number_format($identity['conflicts']) }}</div></div></div>
                    </div>
                    <p class="small text-muted mt-3 mb-0">التعارضات القديمة لا تُحل تلقائياً ولا يُخترع بريد بديل. الاستعادة تثق فقط بالبريد Canonical الموثّق.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4"><h5 class="mb-0">جاهزية Resend</h5></div>
                <div class="card-body px-4 pt-2">
                    <div class="health-row d-flex justify-content-between"><span>API Key</span><span class="badge bg-{{ $provider['api_key_configured'] ? 'success' : 'danger' }}">{{ $provider['api_key_configured'] ? 'مهيأ' : 'غير مهيأ' }}</span></div>
                    <div class="health-row d-flex justify-content-between"><span>Webhook Signature</span><span class="badge bg-{{ $provider['webhook_secret_configured'] ? 'success' : 'danger' }}">{{ $provider['webhook_secret_configured'] ? 'مهيأ' : 'غير مهيأ' }}</span></div>
                    <div class="health-row d-flex justify-content-between"><span>المرسل</span><span class="email-cell">{{ $provider['from_name'] }} &lt;{{ $provider['from_address'] }}&gt;</span></div>
                    <div class="health-row d-flex justify-content-between"><span>التسجيل</span><strong>{{ $provider['registration_channel'] }}</strong></div>
                    <div class="health-row d-flex justify-content-between"><span>استعادة كلمة المرور</span><strong>{{ $provider['password_reset_channel'] }}</strong></div>
                    <div class="health-row d-flex justify-content-between"><span>استعادة PIN</span><strong>{{ $provider['pin_recovery_channel'] }}</strong></div>
                    <div class="health-row d-flex justify-content-between"><span>سياسة OTP</span><strong>{{ $provider['ttl_minutes'] }} دقائق · {{ $provider['max_attempts'] }} محاولات · إعادة {{ $provider['resend_seconds'] }}ث</strong></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card filter-card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label small text-muted">بحث</label>
                    <input class="form-control" name="q" value="{{ $filters['q'] }}" placeholder="رقم المستخدم، Challenge ID، Provider ID، أو البريد">
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small text-muted">الغرض</label>
                    <select class="form-select" name="purpose">
                        <option value="">كل الأغراض</option>
                        @foreach($purposes as $purpose)
                            <option value="{{ $purpose }}" @selected($filters['purpose'] === $purpose)>{{ $purposeLabels[$purpose] ?? $purpose }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 col-lg-2">
                    <label class="form-label small text-muted">التسليم</label>
                    <select class="form-select" name="status">
                        <option value="">كل الحالات</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status][0] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 col-lg-2 d-flex gap-2">
                    <button class="btn btn-primary flex-fill">تصفية</button>
                    <a class="btn btn-outline-secondary" href="{{ route('admin.amial.email-center.index') }}">مسح</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">سجل تحديات البريد</h5>
                <div class="small text-muted">لا يتم عرض الرمز، token hash، verification token أو مفاتيح المزود.</div>
            </div>
            <span class="badge bg-light text-dark">{{ number_format($challenges->total()) }} نتيجة</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>الوقت</th><th>المستخدم</th><th>البريد</th><th>الغرض</th><th>التسليم</th><th>التحقق</th><th>المحاولات</th><th>Challenge</th><th>الإجراء</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($challenges as $row)
                    @php
                        $s = $statusLabels[$row->delivery_status] ?? [$row->delivery_status, 'secondary'];
                        $isExpired = \Illuminate\Support\Carbon::parse($row->expires_at)->isPast();
                    @endphp
                    <tr>
                        <td><div class="small">{{ $row->created_at }}</div></td>
                        <td>{{ $row->user_id ? '#'.$row->user_id : '—' }}</td>
                        <td class="email-cell">{{ $row->identifier_display }}</td>
                        <td>{{ $purposeLabels[$row->purpose] ?? $row->purpose }}</td>
                        <td><span class="badge bg-{{ $s[1] }}">{{ $s[0] }}</span>@if($row->last_error_display)<div class="tiny text-danger mt-1" title="{{ $row->last_error_display }}">{{ \Illuminate\Support\Str::limit($row->last_error_display, 55) }}</div>@endif</td>
                        <td>
                            @if($row->verified_at)<span class="badge bg-success">تم</span>
                            @elseif($row->consumed_at)<span class="badge bg-secondary">مستهلك</span>
                            @elseif($isExpired)<span class="badge bg-secondary">منتهي</span>
                            @else<span class="badge bg-warning text-dark">بانتظار</span>@endif
                        </td>
                        <td>{{ $row->attempts }} / {{ $row->max_attempts }}</td>
                        <td class="challenge-cell">{{ $row->challenge_id }}</td>
                        <td>
                            @if($canManage && !$row->consumed_at && !$isExpired)
                                <form method="POST" action="{{ route('admin.amial.email-center.challenges.revoke', $row->challenge_id) }}" onsubmit="return confirm('إبطال هذا التحدي فوراً؟ لن يقبل الرمز بعد ذلك.');">
                                    @csrf
                                    <button class="btn btn-outline-danger btn-sm">إبطال</button>
                                </form>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-5">لا توجد نتائج مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($challenges->hasPages())
            <div class="card-footer bg-white">{{ $challenges->links() }}</div>
        @endif
    </div>

    <div class="small text-muted mt-3">كل فتح للمركز، تصدير، كشف PII، أو إبطال تحدّي يُكتب في سجل التدقيق المتسلسل SHA-256.</div>
</div>
@endsection
