@extends('layouts.admin.app')

@section('title', 'حالة التشغيل')

@php
    /**
     * AMIAL-OPS-CONSOLE-001 — الصمت هو ما تبحث عنه هذه الشاشة.
     *
     * عطل تنزيل الإيصالات بقي أسابيع لأن المهامّ كانت تنتظر في الطابور بلا
     * خطأ ولا سجلّ ولا مهمّة فاشلة. فالترتيب هنا بالعمر لا بالعدد: طابورٌ
     * فيه ألف مهمّة عمرها ثوانٍ نظامٌ مشغول، وطابورٌ فيه واحدة عمرها ساعة
     * عاملٌ ميّت.
     */
    $fmtAge = function (int $s) {
        if ($s < 60) return $s . ' ثانية';
        if ($s < 3600) return floor($s / 60) . ' دقيقة';
        if ($s < 86400) return floor($s / 3600) . ' ساعة';
        return floor($s / 86400) . ' يوم';
    };

    $stalled = collect($snapshot['queues'])->firstWhere('stalled', true);
    $badStorage = collect($snapshot['storage'])->firstWhere('writable', false);
    $hasFailed = ($snapshot['failed']['total'] ?? 0) > 0;
    $healthy = !$stalled && !$badStorage && !$hasFailed;
@endphp

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-settings-outlined" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">حالة التشغيل</h2>
        <span class="badge badge-soft-secondary ms-auto">
            آخر فحص: {{ $snapshot['checked_at'] }}
        </span>
        <a href="{{ url()->current() }}" class="btn btn-sm btn-outline-secondary">
            <i class="tio-refresh"></i> تحديث
        </a>
    </div>

    @foreach (['success' => 'success', 'error' => 'danger'] as $key => $tone)
        @if (session($key))
            <div class="alert alert-{{ $tone }}">{{ session($key) }}</div>
        @endif
    @endforeach

    {{-- الحكم العامّ أوّلاً: من يفتح الشاشة يريد جواباً في ثانية --}}
    <div class="card border-{{ $healthy ? 'success' : 'danger' }} mb-4">
        <div class="card-body d-flex align-items-center gap-3">
            <i class="tio-{{ $healthy ? 'checkmark-circle' : 'warning' }} text-{{ $healthy ? 'success' : 'danger' }}"
               style="font-size:32px"></i>
            <div>
                <h4 class="mb-1 text-{{ $healthy ? 'success' : 'danger' }}">
                    {{ $healthy ? 'كل شيء يعمل' : 'يحتاج تدخّلاً' }}
                </h4>
                <small class="text-muted">
                    @if ($stalled)
                        طابور «{{ $stalled['queue'] }}» متوقّف منذ {{ $fmtAge($stalled['oldest_seconds']) }}
                        — العامل غالباً لا يستمع إليه أو متوقّف.
                    @elseif ($badStorage)
                        مجلّد «{{ $badStorage['dir'] }}» غير قابل للكتابة — لن يُولَّد أي مستند.
                    @elseif ($hasFailed)
                        {{ $snapshot['failed']['total'] }} مهمّة فاشلة تنتظر مراجعة.
                    @else
                        الطوابير تسير، والمجلّدات تُكتب، ولا مهامّ فاشلة.
                    @endif
                </small>
            </div>
        </div>
    </div>

    {{-- الطوابير --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">الطوابير</h5>
            <small class="text-muted">العمر أهمّ من العدد: مهمّة واحدة عمرها ساعة تعني عاملاً ميّتاً</small>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>الطابور</th>
                        <th>منتظرة</th>
                        <th>عمر أقدمها</th>
                        <th>الحال</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($snapshot['queues'] as $q)
                    <tr>
                        <td><code>{{ $q['queue'] }}</code></td>
                        <td>{{ number_format($q['pending']) }}</td>
                        <td>{{ $q['pending'] ? $fmtAge($q['oldest_seconds']) : '—' }}</td>
                        <td>
                            @if ($q['stalled'])
                                <span class="badge badge-soft-danger">متعثّر</span>
                            @elseif ($q['pending'])
                                <span class="badge badge-soft-info">يسير</span>
                            @else
                                <span class="badge badge-soft-success">فارغ</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- المهامّ الفاشلة، مجمَّعةً بسببها --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                المهامّ الفاشلة
                <span class="badge badge-soft-{{ $hasFailed ? 'danger' : 'success' }}">
                    {{ number_format($snapshot['failed']['total']) }}
                </span>
            </h5>
            <small class="text-muted">مجمَّعة بسببها — عشرون فشلاً بسبب واحد سطرٌ واحد</small>
        </div>

        @if (empty($snapshot['failed']['groups']))
            <div class="card-body text-muted">لا مهامّ فاشلة.</div>
        @else
        <div class="table-responsive">
            <table class="table table-borderless table-align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>المهمّة</th>
                        <th>الطابور</th>
                        <th>السبب</th>
                        <th>العدد</th>
                        <th>آخر مرّة</th>
                        @if ($canRetry)<th>إجراء</th>@endif
                    </tr>
                </thead>
                <tbody>
                @foreach ($snapshot['failed']['groups'] as $g)
                    <tr>
                        <td><strong>{{ $g['job'] }}</strong></td>
                        <td><code>{{ $g['queue'] }}</code></td>
                        <td style="max-width:420px">
                            <small class="text-danger">{{ $g['error'] }}</small>
                        </td>
                        <td><span class="badge badge-soft-warning">{{ $g['count'] }}</span></td>
                        <td><small class="text-muted">{{ $g['last_at'] }}</small></td>
                        @if ($canRetry)
                        <td>
                            <form method="POST" action="{{ route('admin.amial.ops.retry') }}" class="d-inline">
                                @csrf
                                <input type="hidden" name="uuid" value="{{ $g['sample_uuid'] }}">
                                <input type="hidden" name="scope" value="group">
                                <button class="btn btn-sm btn-outline-primary"
                                        onclick="return confirm('إعادة {{ $g['count'] }} مهمّة إلى الطابور؟ أصلِح السبب أوّلاً وإلّا فشلت من جديد.')">
                                    إعادة الصنف
                                </button>
                            </form>
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="row">
        {{-- المجلّدات --}}
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">مجلّدات التخزين</h5>
                    <small class="text-muted">تُجرَّب الكتابة فعلاً — الوجود لا يكفي</small>
                </div>
                <div class="card-body">
                    @foreach ($snapshot['storage'] as $d)
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                            <code>storage/app/{{ $d['dir'] }}</code>
                            @if ($d['writable'])
                                <span class="badge badge-soft-success">تُكتب</span>
                            @else
                                <span class="badge badge-soft-danger" title="{{ $d['error'] }}">لا تُكتب</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- المستندات --}}
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">المستندات</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>مستندات مخبّأة</span>
                        <strong>{{ number_format($snapshot['documents']['cached']) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-2">
                        <span>إيصالات بلا ملفّ بعد</span>
                        <strong class="{{ $snapshot['documents']['receipts_without_pdf'] > 20 ? 'text-danger' : '' }}">
                            {{ number_format($snapshot['documents']['receipts_without_pdf']) }}
                        </strong>
                    </div>
                    <small class="text-muted d-block mt-2">
                        عددٌ كبير من الإيصالات بلا ملفّ يعني أن التوليد لا يعمل،
                        فيُصيَّر كلٌّ منها داخل الطلب ويُقطع الاتصال.
                    </small>
                </div>
            </div>
        </div>
    </div>

    {{-- تشخيص إيصال بعينه --}}
    @if ($canRetry)
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">تشخيص إيصال</h5>
            <small class="text-muted">يُشغّل المسار الحقيقي ويطبع الاستثناء بسطره — بلا وصول إلى الخادم</small>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.amial.ops.pdf-doctor') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label">رقم الإيصال</label>
                    <input type="number" name="receipt_id" class="form-control" required min="1">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary">شخّص</button>
                </div>
            </form>

            @if (session('doctor_output'))
                <pre class="bg-dark text-light p-3 mt-3 rounded" dir="ltr"
                     style="max-height:320px;overflow:auto">{{ session('doctor_output') }}</pre>
            @endif
        </div>
    </div>
    @endif

</div>
@endsection
