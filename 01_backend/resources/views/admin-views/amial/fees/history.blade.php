@extends('layouts.admin.app')

@section('title', 'سجلّ تغييرات الرسوم — أميال باي')

@section('content')
{{--
    AMIAL-FEE-TRUTH-020 — **§14 و§20: التدقيقُ يجيب «من فعل هذا؟».**

    ══════════════════════════════════════════════════════════════════════
    كان السجلُّ يعرض `admin_id = 7` و`old_values`/`new_values` كتلتَي JSON
    خام. ومن يراجع تغييراً ماليّاً بعد شهرين لا يعرف من «٧»، ولا يستطيع أن
    يقول **ما الذي تغيّر بالضبط** بين نسختين إلّا بالمقارنة بالعين.

    فصار: **اسمٌ لا رقم**، و**فرقٌ محسوبٌ حقلاً حقلاً** لا كتلتان متجاورتان.
--}}
@php
    /**
     * حقولُ التسعيرة التي يُهمّ فرقُها — وأسماؤها العربيّة.
     *
     * ولا تُقارَن الحقولُ كلُّها: `updated_at` و`id` تتغيّر في كلّ نسخةٍ
     * ولا تعني شيئاً، وعرضُها يُغرق الفرقَ الحقيقيَّ في ضوضاء.
     */
    $compare = [
        'fee_type' => 'نوعُ الرسم',
        'percent_rate' => 'النسبة %',
        'fixed_amount' => 'المبلغُ الثابت',
        'min_fee' => 'أدنى رسم',
        'max_fee' => 'أعلى رسم',
        'agent_commission_percent' => 'حصّةُ الوكيل %',
        'agent_commission_fixed' => 'حصّةُ الوكيل الثابتة',
        'bearer' => 'يتحمّلها',
        'applies_to' => 'تُطبَّق على',
        'zone_code' => 'المنطقة',
    ];

    $fmt = function ($v) {
        if ($v === null || $v === '') return '—';
        if (is_numeric($v)) return rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        return match ((string) $v) {
            'sender' => 'المرسِل', 'receiver' => 'المستلِم', 'merchant' => 'التاجر',
            'customer' => 'عميل', 'agent' => 'وكيل',
            'percent' => 'نسبة', 'fixed' => 'ثابت', 'percent_plus_fixed' => 'نسبة + ثابت',
            default => (string) $v,
        };
    };
@endphp

<div class="content container-fluid">

    @include('admin-views.amial.fees._shell')

    {{-- ══ اختيارُ العمليّة ══ --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="fee-filters"
                  onsubmit="event.preventDefault();
                            var c=this.querySelector('#h-code').value;
                            window.location = c ? '{{ url('admin/amial/fees/history') }}/'+encodeURIComponent(c)
                                                : '{{ route('admin.amial.fees.history') }}';">
                <div>
                    <label class="form-label small mb-1" for="h-code">العمليّة</label>
                    <select class="form-select" id="h-code">
                        <option value="">كلُّ العمليّات — آخرُ التغييرات</option>
                        @foreach($operations as $c => $op)
                            <option value="{{ $c }}" @selected($code === $c)>
                                {{ $op->labelAr }} — {{ $c }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="f-actions">
                    <button class="btn btn-primary" type="submit"><i class="tio-filter-list"></i> عرض</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ══ خطُّ زمنِ النسخ ══ --}}
    @if($code !== null)
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h5 class="card-header-title mb-0">نسخُ «{{ $label }}»</h5>
                <span class="badge bg-light text-dark">{{ $versions->total() }} نسخة</span>
            </div>

            <div class="fee-scroll">
                <table class="table table-borderless table-thead-bordered table-align-middle mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th>النسخة</th>
                        <th>الحالة</th>
                        <th>تُطبَّق على</th>
                        <th>المنطقة</th>
                        <th>الرسم</th>
                        <th>حصّةُ الوكيل</th>
                        <th>يتحمّلها</th>
                        <th>سرت من</th>
                        <th>حتّى</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($versions as $v)
                        <tr @class(['table-light' => $v->is_active])>
                            <td>
                                <span class="font-monospace fw-bold">v{{ $v->version }}</span>
                            </td>
                            <td>
                                @if($v->is_active)
                                    <span class="fee-state s-priced">سارية</span>
                                @else
                                    <span class="fee-state s-not_wired">منتهية</span>
                                @endif
                            </td>
                            <td><small>{{ $fmt($v->applies_to) }}</small></td>
                            <td><small>{{ \App\Services\ZonePolicyService::zoneNameAr($v->zone_code) }}</small></td>
                            <td class="money">
                                @if($v->fee_type !== 'fixed'){{ $fmt($v->percent_rate) }}%@endif
                                @if($v->fee_type === 'percent_plus_fixed') + @endif
                                @if($v->fee_type !== 'percent'){{ $fmt($v->fixed_amount) }}@endif
                            </td>
                            <td class="money">
                                {{ $fmt($v->agent_commission_percent) }}%
                                @if((float) $v->agent_commission_fixed > 0)
                                    + {{ $fmt($v->agent_commission_fixed) }}
                                @endif
                            </td>
                            <td><small>{{ $fmt($v->bearer) }}</small></td>
                            <td>
                                <small class="font-monospace" style="direction:ltr;display:inline-block">
                                    {{ optional($v->effective_from)->format('Y-m-d H:i') ?: '—' }}
                                </small>
                            </td>
                            <td>
                                <small class="font-monospace" style="direction:ltr;display:inline-block">
                                    {{ optional($v->effective_to)->format('Y-m-d H:i') ?: 'الآن' }}
                                </small>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <div class="fee-empty">
                                    <i class="tio-history"></i>
                                    <div class="fw-bold">لم تُضبط تسعيرةٌ لهذه العمليّة بعد</div>
                                    <div class="small">فهي تمرّ اليوم بلا رسم.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if($versions->hasPages())
                <div class="card-footer">{{ $versions->links() }}</div>
            @endif
        </div>
    @endif

    {{-- ══ سجلُّ التغييرات ══ --}}
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h5 class="card-header-title mb-0">
                {{ $code === null ? 'آخرُ التغييرات على كلّ العمليّات' : 'تغييراتُ هذه العمليّة' }}
            </h5>
            <span class="badge bg-light text-dark">{{ $logs->count() }}</span>
        </div>

        <div class="card-body">
            @forelse($logs as $log)
                {{--
                    **كتلةُ `@php` واحدةٌ لا أربعُ متجاورات.**

                    فمُستخرِجُ Blade للكتل الخام يبدأ من أوّل `@php` — ولو كانت
                    `@php(...)` سطراً واحداً — وينتهي عند أوّل `@endphp`.
                    فمزجُ الصيغتين يبتلع ما بينهما، **فتخرج توجيهاتُ الحلقات
                    نصّاً حرفيّاً في الصفحة** وتسقط الصفحةُ بخطأ تحليل.
                --}}
                @php
                    $old = (array) $log->old_values;
                    $new = (array) $log->new_values;
                    $diff = [];

                    foreach ($compare as $field => $fieldLabel) {
                        $a = $old[$field] ?? null;
                        $b = $new[$field] ?? null;

                        // **المقارنةُ نصّيّةٌ بعد التطبيع** — `2.50` و`2.5000`
                        // القيمةُ نفسُها، وعرضُهما «تغييراً» يُغرق الحقيقيَّ.
                        $an = is_numeric($a) ? rtrim(rtrim((string) $a, '0'), '.') : $a;
                        $bn = is_numeric($b) ? rtrim(rtrim((string) $b, '0'), '.') : $b;

                        if ((string) $an !== (string) $bn) {
                            $diff[] = ['label' => $fieldLabel, 'from' => $a, 'to' => $b];
                        }
                    }
                @endphp

                <div class="d-flex gap-3 pb-3 mb-3 border-bottom">
                    <div class="text-center" style="flex:0 0 auto;width:2.5rem">
                        @switch($log->action)
                            @case('created')
                                <i class="tio-add-circle" style="color:var(--amial-success);font-size:1.4rem"></i>
                                @break
                            @case('deactivated')
                                <i class="tio-remove-circle" style="color:var(--amial-danger);font-size:1.4rem"></i>
                                @break
                            @default
                                <i class="tio-edit" style="color:var(--amial-info);font-size:1.4rem"></i>
                        @endswitch
                    </div>

                    <div style="min-width:0;flex:1 1 auto">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <strong>
                                @switch($log->action)
                                    @case('created') أُنشئت نسخةٌ جديدة @break
                                    @case('deactivated') عُطّلت نسخة @break
                                    @default {{ $log->action }}
                                @endswitch
                            </strong>

                            <span class="badge bg-light text-dark">
                                {{ \App\Support\Fees\FeeOperationRegistry::label($log->code) }}
                            </span>

                            @if(isset($new['version']))
                                <span class="badge bg-light text-dark font-monospace">v{{ $new['version'] }}</span>
                            @elseif(isset($old['version']))
                                <span class="badge bg-light text-dark font-monospace">v{{ $old['version'] }}</span>
                            @endif
                        </div>

                        <div class="small text-muted mt-1">
                            {{--
                                **§14 — اسمٌ لا رقمُ هويّة.**
                                وحسابٌ حُذف يُقال «مستخدم #7» صراحةً، فلا
                                تُترك خانةٌ فارغةٌ تُقرأ «لا فاعل».
                            --}}
                            بواسطة
                            <strong>
                                {{ $log->admin_id
                                    ? ($actors[$log->admin_id] ?? ('مستخدم #' . $log->admin_id))
                                    : 'النظام' }}
                            </strong>
                            ·
                            <span class="font-monospace" style="direction:ltr;display:inline-block">
                                {{ optional($log->created_at)->format('Y-m-d H:i') }}
                            </span>
                            @if($log->ip)
                                · <span class="font-monospace" style="direction:ltr;display:inline-block">{{ $log->ip }}</span>
                            @endif
                        </div>

                        @if(! empty($new['reason']))
                            <div class="alert alert-light py-2 px-3 mt-2 mb-0 small">
                                <strong>السبب:</strong> {{ $new['reason'] }}
                            </div>
                        @endif

                        {{-- ══ **الفرقُ حقلاً حقلاً** ══ --}}
                        @if($diff !== [])
                            <div class="fee-scroll mt-2">
                                <table class="table table-sm table-bordered mb-0" style="max-width:560px">
                                    <thead class="thead-light">
                                    <tr><th>الحقل</th><th>قبل</th><th>بعد</th></tr>
                                    </thead>
                                    <tbody>
                                    @foreach($diff as $d)
                                        <tr>
                                            <td class="small">{{ $d['label'] }}</td>
                                            <td class="small money text-muted">{{ $fmt($d['from']) }}</td>
                                            <td class="small money fw-bold">{{ $fmt($d['to']) }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @elseif($log->action === 'created' && $new !== [])
                            <div class="small text-muted mt-1">أوّلُ تسعيرةٍ لهذه التركيبة — لا سابقةَ تُقارَن بها.</div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="fee-empty">
                    <i class="tio-history"></i>
                    <div class="fw-bold">لا تغييراتٍ مسجَّلة</div>
                    <div class="small">كلُّ إنشاءٍ أو تعطيلٍ لتسعيرةٍ يُسجَّل هنا باسمه وتاريخه.</div>
                </div>
            @endforelse
        </div>
    </div>

</div>
@endsection
