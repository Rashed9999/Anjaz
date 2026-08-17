@extends('layouts.admin.app')

@section('title', 'العمليّات خلف الرقم — أميال باي')

@section('content')
{{--
    AMIAL-FEE-TRUTH-019 — **§19: من الرقم إلى العمليّة إلى النسخة.**

    ══════════════════════════════════════════════════════════════════════
    `الإجمالي ← العمليّات ← لقطةُ الرسم ← الدفتر ← التدقيق`

    و`amial-admin-command` تقولها نصّاً: «Never display an unexplained
    financial number». فكان تقريرُ الأرباح يعرض مجموعاً ولا سبيلَ إلى ما
    كوّنه — ومن سُئل «من أين جاء هذا الرقم؟» لم يكن له جوابٌ إلّا الثقة.

    **ولقطةُ الرسم أهمُّ عمودٍ هنا**: `fee_scheme_id` و`fee_scheme_version`
    مخزَّنان على كلّ حركة، فالعمليّةُ التاريخيّةُ تبقى مفسَّرةً بالنسخة التي
    طُبّقت عليها **فعلاً** ولو تغيّرت التسعيرةُ عشرَ مرّاتٍ بعدها.
--}}
@php($money = fn ($v) => number_format((float) $v, 2))

<div class="content container-fluid">

    @include('admin-views.amial.fees._shell')

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a class="btn btn-sm btn-outline-secondary"
           href="{{ route('admin.amial.fees.profit', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">
            <i class="tio-chevron-right"></i> عودةٌ إلى الأرباح
        </a>
        <span class="badge bg-light text-dark">{{ $typeLabel }}</span>
        <span class="badge bg-light text-dark">
            {{ $from->toDateString() }} ← {{ $to->toDateString() }}
        </span>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h5 class="card-header-title mb-0">العمليّاتُ التي حُصّل منها رسم</h5>
            <span class="badge bg-light text-dark">{{ number_format($transactions->total()) }} عمليّة</span>
        </div>

        <div class="fee-scroll">
            <table class="table table-borderless table-thead-bordered table-align-middle mb-0">
                <thead class="thead-light">
                <tr>
                    <th>رقمُ العمليّة</th>
                    <th>النوع</th>
                    <th>المبلغ</th>
                    <th>الرسم</th>
                    <th>لقطةُ التسعيرة</th>
                    <th>المنطقة</th>
                    <th>التاريخ</th>
                    <th class="text-center">التتبّع</th>
                </tr>
                </thead>
                <tbody>
                @forelse($transactions as $t)
                    @php($feeCode = \App\Services\FeeProfitReportService::feeCodeFor((string) $t->transaction_type))
                    <tr>
                        <td>
                            <span class="font-monospace small">{{ $t->transaction_id }}</span>
                            @if($t->ref_trans_id)
                                <br><small class="text-muted">مرتبطةٌ بـ {{ $t->ref_trans_id }}</small>
                            @endif
                        </td>

                        <td>
                            {{ \App\Services\FeeProfitReportService::typeLabel((string) $t->transaction_type) }}
                            <br><small class="text-muted font-monospace">{{ $t->transaction_type }}</small>
                        </td>

                        <td class="money">{{ $money($t->amount) }}</td>
                        <td class="money fw-bold">{{ $money($t->charge) }}</td>

                        {{--
                            **وهذا العمودُ هو الفرقُ بين تقريرٍ يُفسَّر وتقريرٍ
                            يُصدَّق.** فبلا لقطةٍ لا يُعرف أيُّ تسعيرةٍ طُبّقت،
                            وحسابُها بالتسعيرة الحاليّة يُنتج رقماً آخر.
                        --}}
                        <td>
                            @if($t->fee_scheme_id)
                                <span class="badge bg-light text-dark font-monospace">
                                    #{{ $t->fee_scheme_id }} · v{{ $t->fee_scheme_version }}
                                </span>
                                @if($feeCode)
                                    <a class="d-block small mt-1"
                                       href="{{ route('admin.amial.fees.history', $feeCode) }}">النسخةُ في السجلّ</a>
                                @endif
                            @else
                                {{-- **ولا يُقرأ الفراغُ «بلا تسعيرة»** — حركاتٌ
                                     أُنشئت قبل أن يُخزَّن الحقلُ أصلاً. --}}
                                <small class="text-muted" title="حركةٌ سابقةٌ لتخزين اللقطة">غيرُ مسجّلة</small>
                            @endif
                        </td>

                        <td><small>{{ \App\Services\ZonePolicyService::zoneNameAr($t->zone_code) }}</small></td>

                        <td>
                            <small class="font-monospace" style="direction:ltr;display:inline-block">
                                {{ optional($t->created_at)->format('Y-m-d H:i') }}
                            </small>
                        </td>

                        <td class="text-center">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                        data-bs-toggle="dropdown" aria-expanded="false"
                                        aria-label="تتبّعُ العمليّة {{ $t->transaction_id }}">
                                    <i class="tio-more-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    {{--
                                        **ولا رابطَ يقود إلى صفحةٍ تتجاهل مرشِّحَه.**

                                        كان يُظنّ أنّ الدفترَ يُبحَث فيه برقم
                                        الحركة، وهو يُبحَث بـ`entry_ulid` أو
                                        بمفتاح التفرّد. فالوصلةُ القائمةُ فعلاً
                                        `idempotency_key` — يكتبه المُسجِّلُ على
                                        الحركة والمرحِّلُ على القيد.
                                    --}}
                                    @if($t->idempotency_key)
                                        <li>
                                            <a class="dropdown-item"
                                               href="{{ route('admin.amial.ledger.page') }}?idempotency_key={{ urlencode((string) $t->idempotency_key) }}">
                                                <i class="tio-book"></i> قيودُ الدفتر
                                            </a>
                                        </li>
                                    @else
                                        <li>
                                            <span class="dropdown-item text-muted"
                                                  title="هذه الحركةُ بلا مفتاح تفرّدٍ مسجَّل، فلا وصلةَ إلى قيدها">
                                                <i class="tio-book"></i> قيودُ الدفتر — غيرُ متتبَّعة
                                            </span>
                                        </li>
                                    @endif
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('admin.amial.audit.index') }}?transaction_id={{ urlencode((string) $t->transaction_id) }}">
                                            <i class="tio-security-check"></i> سجلُّ التدقيق
                                        </a>
                                    </li>
                                    @if($feeCode)
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item"
                                               href="{{ route('admin.amial.fees.history', $feeCode) }}">
                                                <i class="tio-history"></i> نسخُ التسعيرة
                                            </a>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="fee-empty">
                                <i class="tio-receipt"></i>
                                <div class="fw-bold">لا عمليّةَ حُصّل منها رسمٌ في هذا النطاق</div>
                                <div class="small">وسّع الفترة، أو أزل مرشِّحَ النوع.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if($transactions->hasPages())
            <div class="card-footer">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
