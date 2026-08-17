@extends('layouts.admin.app')

@section('title', 'سجلّ العمليّات المسعَّرة — أميال باي')

@section('content')
{{--
    AMIAL-FEE-TRUTH-009 — **سجلُّ العمليّات: ما يُسعَّر، ومن يستهلكه.**

    ══════════════════════════════════════════════════════════════════════
    هذه الشاشةُ تجيب السؤالَ الذي لم يكن له جوابٌ في أيّ مكان:

      «ما العمليّاتُ التي أستطيع تسعيرَها؟ وأيُّها يُخصَم منها مالٌ فعلاً؟
       ومن يحسبها في الشيفرة؟»

    وبلاها يبقى الأدمنُ يضبط رمزاً لا يعرف أين يُطبَّق — أو يظنّ رمزاً
    مضبوطاً وهو غيرُ موصولٍ بمسارٍ ماليٍّ أصلاً.
--}}
<div class="content container-fluid">

    @include('admin-views.amial.fees._shell')

    <div class="alert alert-info d-flex align-items-start gap-2 small" role="note">
        <i class="tio-info-outined mt-1"></i>
        <div>
            هذا السجلُّ هو <strong>المصدرُ الوحيد</strong> لقائمة العمليّات المسعَّرة:
            منه تُبنى القائمةُ المنسدلةُ في «نسخةٌ جديدة»، ومنه يتحقّق المحرّكُ من
            الجهات والمتحمّلين، وعليه تُقاس الحراسةُ في الاختبارات.
            <span class="d-block mt-1">
                فما ليس هنا <strong>لا يُسعَّر</strong>، وما هنا وليس له مستهلكٌ حيٌّ
                <strong>يُقال ذلك صراحةً</strong> مع سببه.
            </span>
        </div>
    </div>

    @foreach($grouped as $cat => $ops)
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h5 class="card-header-title mb-0">{{ $categories[$cat] ?? $cat }}</h5>
                <span class="badge bg-light text-dark">{{ count($ops) }}</span>
            </div>

            <div class="fee-scroll">
                <table class="table table-borderless table-thead-bordered table-align-middle mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th>العمليّة</th>
                        <th>تُطبَّق على</th>
                        <th>يتحمّلها</th>
                        <th>حصّةُ وكيل</th>
                        <th>الحالة</th>
                        <th>المسؤول عنها</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($ops as $op)
                        <tr id="op-{{ $op->code }}">
                            <td style="min-width:200px">
                                <span class="fw-bold">{{ $op->labelAr }}</span><br>
                                <small class="text-muted font-monospace">{{ $op->code }}</small>
                                <small class="text-muted d-block">{{ $op->labelEn }}</small>
                            </td>

                            <td>
                                @foreach($op->actors as $a)
                                    <span class="badge bg-light text-dark">
                                        @switch($a)
                                            @case('customer') عميل @break
                                            @case('merchant') تاجر @break
                                            @case('agent') وكيل @break
                                            @default {{ $a }}
                                        @endswitch
                                    </span>
                                @endforeach
                            </td>

                            <td>
                                @foreach($op->bearers as $b)
                                    <span class="badge bg-light text-dark">
                                        @switch($b)
                                            @case('sender') المرسِل @break
                                            @case('receiver') المستلِم @break
                                            @case('merchant') التاجر @break
                                            @default {{ $b }}
                                        @endswitch
                                    </span>
                                @endforeach
                            </td>

                            {{--
                                **وهذا العمودُ هو سببُ الشاشة.**

                                فحقلُ «حصّة الوكيل» كان معروضاً في كلّ نسخة،
                                ومنه في `SEND_MONEY` — ولا وكيلَ في التحويل
                                بين محفظتين. فرقمٌ هناك يُقتطع من ربح المنصّة
                                ويُقيَّد لحصّةٍ لا صاحبَ لها، **والدفترُ
                                متوازنٌ فلا يُنبّه أحد**.
                            --}}
                            <td>
                                @if($op->agentCommission)
                                    <span class="fee-state s-priced">تنطبق</span>
                                @else
                                    <small class="text-muted" title="لا يدَ بشريّةً في هذه العمليّة">لا تنطبق</small>
                                @endif
                            </td>

                            <td style="min-width:220px">
                                @if($op->isLive())
                                    <span class="fee-state s-priced">موصولة</span>
                                    <div class="small text-muted mt-1">
                                        تُحسب في:
                                        @foreach($op->consumers as $c)
                                            <code class="d-block" style="font-size:.7rem">{{ $c }}</code>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="fee-state s-not_wired">غيرُ موصولة</span>
                                    <div class="small text-muted mt-1">{{ $op->notWiredReason }}</div>
                                @endif
                            </td>

                            <td style="min-width:180px">
                                <small class="text-muted font-monospace" style="font-size:.7rem">{{ $op->owner }}</small>
                                @if($op->isLive() && $canWrite)
                                    <a class="btn btn-sm btn-outline-primary d-block mt-2"
                                       href="{{ route('admin.amial.fees.create', ['code' => $op->code, 'applies_to' => $op->actors[0]]) }}">
                                        <i class="tio-edit"></i> ضبطُ التسعيرة
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

</div>
@endsection
