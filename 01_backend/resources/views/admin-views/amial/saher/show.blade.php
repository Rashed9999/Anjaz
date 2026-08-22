@extends('layouts.admin.app')

@section('title', 'ساهر — ' . $finding->reference)

@php
    $sevLabel = [
        'CRITICAL' => ['حرج', 'danger'], 'HIGH' => ['عالٍ', 'warning'],
        'MEDIUM' => ['متوسّط', 'info'], 'LOW' => ['منخفض', 'secondary'],
        'INFO' => ['إخباريّ', 'light'],
    ];
    $confLabel = [
        'PROVEN' => 'مثبَت — قِيس مباشرةً من المصدر',
        'HIGH_CONFIDENCE' => 'ثقةٌ عالية — استُنتج من قياسٍ سليم',
        'SUSPECTED' => 'مشتبَه — مؤشِّرٌ يستحقّ النظر، ولا يُبنى عليه حذف',
        'INFORMATIONAL' => 'إخباريّ — يُقال ولا يُطالَب بفعل',
    ];
    $kindLabel = [
        'ROUTE_MIDDLEWARE' => 'وسائطُ المسار', 'CODE_LINE' => 'سطرُ شيفرة',
        'DB_ROW' => 'صفٌّ من القاعدة', 'QUERY_RESULT' => 'ناتجُ استعلام',
        'COMMAND_OUTPUT' => 'مخرجاتُ أمر', 'ABSENCE' => 'غيابُ الشيء — وهو دليل',
    ];
    [$sl, $sc] = $sevLabel[$finding->severity] ?? [$finding->severity, 'secondary'];
@endphp

@section('content')
<div class="content container-fluid">

    <a href="{{ route('admin.amial.saher.index') }}" class="btn btn-sm btn-white mb-3">
        <i class="tio-chevron-right"></i> عودة إلى ساهر
    </a>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-2">
                <span class="text-monospace">{{ $finding->reference }}</span>
                <span class="badge badge-soft-{{ $sc }}">{{ $sl }}</span>
                <span class="badge badge-soft-secondary">{{ $finding->category }}</span>
                <span class="text-muted small">{{ $finding->rule_id }}</span>
            </div>

            <h3 class="mb-3">{{ $finding->title }}</h3>

            {{-- **درجةُ الثقة تُشرَح لا تُختصَر برمز.** فقارئٌ يرى
                 `SUSPECTED` ولا يعرف معناها إمّا يتجاهل حقّاً أو يحذف
                 شيفرةً حيّة. --}}
            <div class="alert alert-light border">
                <strong>درجةُ الثقة:</strong>
                {{ $confLabel[$finding->confidence] ?? $finding->confidence }}
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <small class="text-muted d-block">المتوقَّع</small>
                    <div>{{ $finding->expected_behavior ?: '—' }}</div>
                </div>
                <div class="col-md-6 mb-3">
                    <small class="text-muted d-block">الواقع</small>
                    <div class="text-danger">{{ $finding->actual_behavior ?: '—' }}</div>
                </div>
                <div class="col-md-6 mb-3">
                    <small class="text-muted d-block">الأثر</small>
                    <div>{{ $finding->impact ?: '—' }}</div>
                </div>
                <div class="col-md-6 mb-3">
                    <small class="text-muted d-block">الإجراءُ المقترح</small>
                    <div>{{ $finding->suggested_action ?: '—' }}</div>
                </div>
            </div>

            <hr>

            <div class="row small text-muted">
                <div class="col-md-3">الأصل: <span class="text-monospace">{{ $finding->asset_key }}</span></div>
                <div class="col-md-3">
                    الموضع:
                    <span class="text-monospace">
                        {{ $finding->file_path ?: '—' }}@if($finding->line_start):{{ $finding->line_start }}@endif
                    </span>
                </div>
                <div class="col-md-2">رُئي أوّلَ مرّة: {{ \Carbon\Carbon::parse($finding->first_seen_at)->diffForHumans() }}</div>
                <div class="col-md-2">آخرَ مرّة: {{ \Carbon\Carbon::parse($finding->last_seen_at)->diffForHumans() }}</div>
                <div class="col-md-2">عددُ المرّات: {{ number_format($finding->occurrence_count) }}</div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         الدليل — **خلف صلاحيّته**.

         خريطةُ الأسطح غير المحروسة هي بعينها خريطةُ الهجوم. فالعددُ
         لمن يراقب، والدليلُ لمن يُصلح.
    ══════════════════════════════════════════════════════════════ --}}
    <div class="card mb-4">
        <div class="card-header"><h4 class="card-header-title">الأدلّة</h4></div>
        <div class="card-body">
            @if(! $canSeeEvidence)
                <div class="alert alert-warning mb-0" data-testid="saher-evidence-denied">
                    <strong>الدليلُ محجوبٌ عن دورك.</strong>
                    يحتاج <span class="text-monospace">saher.evidence.view</span> —
                    وهي أضيقُ من صلاحيّة العرض عمداً: الدليلُ يحمل مقتطعاتِ
                    شيفرةٍ ومواضعَ حرّاسٍ غائبة.
                </div>
            @elseif($evidence->isEmpty())
                {{-- **ولا يُقال «لا مشكلة»** — اكتشافٌ بلا دليلٍ يُقرأ نقصاً
                     في الجامع لا نظافةً في النظام. --}}
                <div class="text-warning mb-0">
                    لا دليلَ مسجَّلٌ لهذا الاكتشاف — وهو نقصٌ في الجامع يُراجَع،
                    لا دلالةَ على سلامة.
                </div>
            @else
                @foreach($evidence as $e)
                    <div class="mb-3">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge badge-soft-info">{{ $kindLabel[$e->kind] ?? $e->kind }}</span>
                            <strong class="small">{{ $e->label_ar }}</strong>
                            @if($e->collected_by)
                                <small class="text-muted text-monospace ms-auto">{{ $e->collected_by }}</small>
                            @endif
                        </div>
                        <pre class="bg-light p-3 rounded small mb-0" dir="ltr"
                             style="white-space:pre-wrap">{{ $e->body }}</pre>
                    </div>
                @endforeach

                <small class="text-muted d-block mt-2">
                    الدليلُ مقتطَعٌ وقتَ القياس ولا يُقرأ من الملفّ الآن —
                    فملفٌّ يُعدَّل بعد الاكتشاف يجعل رقمَ السطر يشير إلى شيءٍ آخر.
                </small>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h4 class="card-header-title">تاريخُ الاكتشاف</h4></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="thead-light">
                    <tr><th>الحدث</th><th>من</th><th>إلى</th><th>الفاعل</th><th>ملاحظة</th><th>متى</th></tr>
                </thead>
                <tbody>
                @foreach($events as $ev)
                    <tr>
                        <td class="text-monospace small">{{ $ev->event }}</td>
                        <td><small>{{ $ev->from_status ?: '—' }}</small></td>
                        <td><small>{{ $ev->to_status ?: '—' }}</small></td>
                        <td><small>{{ ['scan' => 'جولةُ فحص', 'admin' => 'مشرف', 'system' => 'النظام'][$ev->actor_type] ?? $ev->actor_type }}</small></td>
                        <td><small class="text-muted">{{ $ev->note }}</small></td>
                        <td><small>{{ \Carbon\Carbon::parse($ev->occurred_at)->diffForHumans() }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
