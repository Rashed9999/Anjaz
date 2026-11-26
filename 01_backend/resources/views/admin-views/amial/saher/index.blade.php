@extends('layouts.admin.app')

@section('title', 'ساهر — رادار النظام')

@php
    $sevLabel = [
        'CRITICAL' => ['حرج', 'danger'], 'HIGH' => ['عالٍ', 'warning'],
        'MEDIUM' => ['متوسّط', 'info'], 'LOW' => ['منخفض', 'secondary'],
        'INFO' => ['إخباريّ', 'light'],
    ];
    $confLabel = [
        'PROVEN' => 'مثبَت', 'HIGH_CONFIDENCE' => 'ثقةٌ عالية',
        'SUSPECTED' => 'مشتبَه', 'INFORMATIONAL' => 'إخباريّ',
    ];
    $statusLabel = [
        'OPEN' => 'مفتوح', 'REOPENED' => 'عاد بعد إغلاق',
        'ACKNOWLEDGED' => 'أُقِرَّ به', 'INVESTIGATING' => 'قيد التحقيق',
        'FIXED_PENDING_VERIFICATION' => 'أُصلح — بانتظار التحقّق',
        'ACCEPTED_RISK' => 'مخاطرةٌ مقبولة', 'SUPPRESSED' => 'مكتوم',
    ];
    $healthLabel = [
        'HEALTHY' => ['يعمل', 'success'], 'DEGRADED' => ['متدهور', 'warning'],
        'UNAVAILABLE' => ['غير متاح', 'danger'], 'STALE' => ['بائت', 'warning'],
        'NOT_CONFIGURED' => ['لم يُشغَّل بعد', 'secondary'],
    ];
@endphp

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="tio-dashboard" style="font-size:24px"></i>
        <div>
            <h2 class="page-header-title mb-0">ساهر</h2>
            <small class="text-muted">رادارُ النظام — يرى قبل أن يرى العميلُ المشكلة</small>
        </div>

        @if(auth('user')->user()?->hasPlatformPermission('saher.scan.run'))
            <form method="POST" action="{{ route('admin.amial.saher.scan') }}" class="ms-auto">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary" data-testid="saher-scan">
                    <i class="tio-refresh"></i> شغّل فحصاً الآن
                </button>
            </form>
        @endif
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    {{-- SAHER-GATE-005 — **«غيرُ متاحٍ هنا» ليس فشلاً.** مصدرُ البوّابة
         يقرأ تاريخَ git، وصورةُ النشر تُبنى بلا `.git`. فكان يُرفع أحمرَ
         في كلّ ضغطة ولا شيءَ مكسور — ولافتةٌ حمراءُ دائمةٌ تُعوّد القارئَ
         تجاهلَها يومَ تصدق. --}}
    @if(session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         صحّةُ ساهر نفسِه — **في الرأس لا في الذيل**.

         لوحةٌ خضراءُ فوق راصدٍ ميّتٍ أسوأ من حمراء. فإن لم تجرِ جولةٌ
         تُقال «لم يُشغَّل» ولا يُعرَض صفرٌ مطمئنّ. (القاعدة السابعة.)
    ══════════════════════════════════════════════════════════════ --}}
    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <strong class="text-muted small">صحّةُ مصادر ساهر:</strong>
                @foreach($sources as $s)
                    @php [$lbl, $col] = $healthLabel[$s->display_health] ?? ['مجهول', 'secondary']; @endphp
                    <span class="badge badge-soft-{{ $col }}" data-testid="saher-source-{{ $s->code }}">
                        {{ $s->label_ar }} — {{ $lbl }}
                        @if($s->last_success_at)
                            <span class="text-muted">· آخر نجاح {{ \Carbon\Carbon::parse($s->last_success_at)->diffForHumans() }}</span>
                        @endif
                    </span>
                    @if($s->health_reason && $s->display_health !== 'HEALTHY')
                        <small class="text-danger">{{ $s->health_reason }}</small>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    {{-- Active containment always appears above the findings queue. --}}
    <div class="card mb-4" data-testid="saher-active-quarantines">
        <div class="card-header">
            <h4 class="card-header-title">
                الاحتواءات الأمنية النشطة
                @if($quarantineReady)
                    <span class="badge badge-soft-{{ $quarantineCount ? 'warning' : 'secondary' }} ms-2">{{ $quarantineCount }}</span>
                @endif
            </h4>
        </div>
        <div class="card-body">
            @if(! $quarantineReady)
                <div class="text-warning">آلية الاحتواء غير مهيأة بعد — يلزم تطبيق ترحيل قاعدة البيانات، ولا يمكن استنتاج عدد الاحتواءات.</div>
            @elseif($quarantineCount === 0)
                <p class="text-muted mb-0">لا توجد مسارات محظورة حالياً.</p>
            @elseif(! $canManageQuarantine)
                <p class="text-warning mb-0">هناك {{ $quarantineCount }} احتواء نشط. تفاصيل المسارات مقصورة على المسؤول الأمني المخوّل.</p>
            @else
                <div class="alert alert-warning small">
                    الاحتواء إجراء مؤقت يوقف وظائف فعلية. راجع كل حالة، وتأكد من إصلاح الكود وإعادة فحص ساهر ثم ارفع الحظر بإذن مستقل.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr>
                            <th>المسار</th><th>تاريخ التفعيل</th><th>المدة</th><th>السبب</th><th>المراجعة</th>
                        </tr></thead>
                        <tbody>
                            @foreach($activeQuarantines as $q)
                                <tr>
                                    <td><code dir="ltr">{{ $q->route_name }}</code></td>
                                    <td><small>{{ $q->created_at }}</small></td>
                                    <td>
                                        @php $quarantineAge = \Carbon\Carbon::parse($q->created_at)->diffInHours(now()); @endphp
                                        <span class="badge badge-soft-{{ $quarantineAge >= 24 ? 'danger' : 'warning' }}">
                                            {{ $quarantineAge }} ساعة
                                        </span>
                                    </td>
                                    <td>{{ \Illuminate\Support\Str::limit($q->reason, 150) }}</td>
                                    <td>
                                        <a href="{{ route('admin.amial.saher.show', $q->finding_id) }}" class="btn btn-sm btn-outline-primary">
                                            مراجعة الاحتواء
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($quarantineCount > 100)
                    <div class="text-warning small mt-2">توجد احتواءات إضافية غير معروضة؛ راجع سجلات المتابعة.</div>
                @endif
            @endif
        </div>
    </div>

    <div class="card mb-4" data-testid="saher-remediation-queue">
        <div class="card-header">
            <h4 class="card-header-title">طلبات الإصلاح الجذري
                @if($remediationReady)
                    <span class="badge badge-soft-{{ $remediationOpenCount ? 'warning' : 'secondary' }} ms-2">{{ $remediationOpenCount }}</span>
                @endif
            </h4>
        </div>
        <div class="card-body">
            @if(! $remediationReady)
                <div class="text-warning">طلبات الإصلاح لم تُفعّل بعد — يلزم ترحيل قاعدة البيانات.</div>
            @elseif($remediationOpenCount === 0)
                <p class="text-muted mb-0">لا توجد طلبات إصلاح مفتوحة مسجلة بعد.</p>
            @elseif(! $canPlanRemediation)
                <p class="text-warning mb-0">هناك {{ $remediationOpenCount }} طلب معالجة مفتوح؛ التفاصيل للمخوّلين فقط.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>الطلب</th><th>الاستراتيجية</th><th>العمر</th><th>المعالجة</th></tr></thead>
                        <tbody>
                            @foreach($openRemediations as $case)
                                <tr>
                                    <td><strong>#{{ $case->id }}</strong></td>
                                    <td><code>{{ $case->strategy }}</code></td>
                                    <td>{{ \Carbon\Carbon::parse($case->created_at)->diffForHumans() }}</td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.amial.saher.show', $case->finding_id) }}">عرض الخطة والأدلة</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($remediationOpenCount > 100)
                    <div class="text-warning small">توجد طلبات إضافية غير معروضة في هذه القائمة المختصرة.</div>
                @endif
            @endif
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         **أهمُّ ما يحتاج تدخّلاً الآن** — قبل الأرقام لا بعدها.

         لوحةٌ تبدأ بأربعة أرقامٍ كبيرة تُعوّد القارئَ أن يقرأ الرقمَ
         ولا يفتح شيئاً.
    ══════════════════════════════════════════════════════════════ --}}
    <div class="card mb-4">
        <div class="card-header">
            <h4 class="card-header-title">
                أهمُّ ما يحتاج تدخّلاً الآن
                @if($reopened > 0)
                    <span class="badge badge-soft-danger ms-2">{{ $reopened }} عادت بعد إغلاق</span>
                @endif
            </h4>
        </div>

        <div class="card-body p-0">
            @if($findings->isEmpty())
                <div class="p-4 text-center text-muted" data-testid="saher-empty">
                    @if(array_filter($filters))
                        <strong>لا اكتشافات تطابق خيارات البحث.</strong>
                        <a href="{{ route('admin.amial.saher.index') }}">عرض جميع النتائج</a>
                    @elseif($runs->isEmpty() && !$sources->contains(fn ($source) => $source->last_success_at !== null))
                        <strong class="text-warning">لم تُشغَّل جولةُ فحصٍ بعد.</strong>
                    @elseif($sources->isEmpty() || $sources->contains(fn ($source) => $source->display_health !== 'HEALTHY'))
                        <strong class="text-warning">تغطية الفحص غير مكتملة.</strong>
                        <div class="small mt-1">راجع حالة كل مصدر وآخر جولة فحص قبل اعتماد النتيجة.</div>
                    @else
                        <strong class="text-success">لا اكتشافاتٍ مفتوحة.</strong>
                    @endif
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-align-middle mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>الرمز</th>
                                <th>الدرجة</th>
                                <th>الثقة</th>
                                <th>العنوان</th>
                                <th>الأصل</th>
                                <th>الحالة</th>
                                <th>منذ</th>
                                <th>مرّات</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($findings as $f)
                            @php [$sl, $sc] = $sevLabel[$f->severity] ?? [$f->severity, 'secondary']; @endphp
                            <tr @if($f->status === 'REOPENED') class="table-warning" @endif>
                                <td>
                                    <a href="{{ route('admin.amial.saher.show', $f->id) }}"
                                       class="text-monospace small">{{ $f->reference }}</a>
                                </td>
                                <td><span class="badge badge-soft-{{ $sc }}">{{ $sl }}</span></td>
                                <td><small class="text-muted">{{ $confLabel[$f->confidence] ?? $f->confidence }}</small></td>
                                <td>{{ $f->title }}</td>
                                <td class="text-monospace small text-muted">{{ $f->asset_key }}</td>
                                <td><small>{{ $statusLabel[$f->status] ?? $f->status }}</small></td>
                                <td><small class="text-muted">{{ \Carbon\Carbon::parse($f->first_seen_at)->diffForHumans() }}</small></td>
                                <td><small>{{ number_format($f->occurrence_count) }}</small></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- الأرقامُ تحت القائمة، لا فوقها --}}
    <div class="row mb-4">
        @foreach(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as $sev)
            @php [$lbl, $col] = $sevLabel[$sev]; @endphp
            <div class="col-md-3">
                <div class="card border-{{ $col }}">
                    <div class="card-body py-3">
                        <small class="text-muted">{{ $lbl }} · مفتوح</small>
                        <h3 class="text-{{ $col }} mb-0"
                            data-testid="saher-count-{{ strtolower($sev) }}">
                            {{ number_format($severityCounts[$sev] ?? 0) }}
                        </h3>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- آخرُ الجولات — **والساقطةُ تُعرَض ساقطة** --}}
    <div class="card">
        <div class="card-header"><h4 class="card-header-title">آخرُ جولات الفحص</h4></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="thead-light">
                    <tr><th>المصدر</th><th>الحالة</th><th>بدأت</th><th>المدّة</th>
                        <th>أصولٌ فُحصت</th><th>جديد</th><th>أُغلق</th><th>السبب عند السقوط</th></tr>
                </thead>
                <tbody>
                @forelse($runs as $r)
                    <tr>
                        <td class="text-monospace small">{{ $r->source_code }}</td>
                        <td>
                            <span class="badge badge-soft-{{ $r->status === 'COMPLETED' ? 'success' : ($r->status === 'FAILED' ? 'danger' : 'info') }}">
                                {{ ['COMPLETED' => 'اكتملت', 'FAILED' => 'سقطت', 'RUNNING' => 'تجري', 'PARTIAL' => 'ناقصة'][$r->status] ?? $r->status }}
                            </span>
                        </td>
                        <td><small>{{ \Carbon\Carbon::parse($r->started_at)->diffForHumans() }}</small></td>
                        <td><small>{{ $r->duration_ms !== null ? number_format($r->duration_ms) . ' م.ث' : '—' }}</small></td>
                        <td>{{ number_format($r->assets_seen) }}</td>
                        <td>{{ number_format($r->findings_opened) }}</td>
                        <td>{{ number_format($r->findings_resolved) }}</td>
                        <td><small class="text-danger">{{ $r->failure_reason }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-3">
                        لم تُسجَّل جولةٌ بعد.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
