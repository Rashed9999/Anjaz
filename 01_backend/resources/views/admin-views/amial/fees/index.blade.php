@extends('layouts.admin.app')

@section('title', 'مركز الرسوم والأرباح — أميال باي')

@section('content')
{{--
    AMIAL-FEE-TRUTH-016 — **نظرةٌ عامّة: ما ضُبط، وما لم يُضبط.**

    ══════════════════════════════════════════════════════════════════════
    كان الجدولُ يُبنى من صفوف `fee_schemes` النشطة. **فالفراغُ لا يُرى.**
    عمليّةٌ بلا تسعيرةٍ تختفي من الشاشة تماماً، والصفحةُ تبدو تامّةً وهي
    ناقصةٌ — وهذا بعينه ما جعل عمليّاتِ الوكيل مجّانيّةً شهوراً: لا صفَّ
    يُنبّه لأنّ لا صفَّ أصلاً.

    فصار الجدولُ يُبنى من **سجلّ العمليّات**: كلُّ تركيبة (‏عمليّة × جهة)
    لها سطرٌ ولو لم تُسعَّر، وحالتُها مكتوبةٌ بالاسم. (‏القاعدة السابعة:
    «غير معروف» ليس صفراً.)
--}}
<div class="content container-fluid">

    @include('admin-views.amial.fees._shell')

    {{-- ══ صحّةُ المحرّك ══ --}}
    <div class="fee-kpis">
        <div class="fee-kpi is-success">
            <span class="k-label">عمليّاتٌ مسعَّرة</span>
            <span class="k-value">{{ $health['priced'] }}</span>
            <span class="k-note">لها نسخةٌ نشطةٌ برسمٍ أكبر من صفر</span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">مجّانيّةٌ بقرار</span>
            <span class="k-value">{{ $health['zero'] }}</span>
            <span class="k-note">نسخةٌ صريحةٌ بصفر — قرارٌ لا نقص</span>
        </div>

        <div class="fee-kpi {{ $health['missing'] > 0 ? 'is-danger' : '' }}">
            <span class="k-label">بلا تسعيرة</span>
            <span class="k-value">{{ $health['missing'] }}</span>
            <span class="k-note">
                @if($health['missing'] > 0)
                    تمرّ اليوم بلا رسم — ولم يقرّر ذلك أحد
                @else
                    لا عمليّةَ حيّةً بلا تسعيرة
                @endif
            </span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">غيرُ موصولةٍ بعد</span>
            <span class="k-value">{{ $health['not_wired'] }}</span>
            <span class="k-note">لا يستهلكها مسارٌ ماليّ — وأسبابُها مكتوبة</span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">الربحُ التراكميّ</span>
            <span class="k-value money">{{ number_format((float) $lifetime['total'], 2) }}</span>
            <span class="k-note">ر.ي — مسوّى وغيرُ مسوّى</span>
        </div>
    </div>

    @if($health['missing'] > 0)
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="tio-warning mt-1"></i>
            <div>
                <strong>{{ $health['missing'] }} عمليّةً حيّةً بلا تسعيرة.</strong>
                تمرّ الآن بلا رسمٍ يُخصَم. وإن كانت مجّانيّةً عمداً فأنشئ لها
                <span class="fw-bold">نسخةً صريحةً بصفر</span> — فيكون للقرار سببٌ وسجلٌّ ومَن اتّخذه،
                ويفترق «قرّرنا ألّا نأخذ» عن «لم نجد ما نأخذ به».
            </div>
        </div>
    @endif

    {{-- ══ المرشِّحات ══ --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('admin.amial.fees.index') }}" class="fee-filters">
                <div>
                    <label class="form-label small mb-1" for="f-zone">المنطقة</label>
                    <select class="form-select" id="f-zone" name="zone">
                        @foreach($zones as $z => $zLabel)
                            <option value="{{ $z }}" @selected($zone === $z)>{{ $zLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="form-label small mb-1" for="f-cat">التصنيف</label>
                    <select class="form-select" id="f-cat" name="category">
                        <option value="">كلُّ التصنيفات</option>
                        @foreach($categories as $ck => $cLabel)
                            <option value="{{ $ck }}" @selected($category === $ck)>{{ $cLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="form-label small mb-1" for="f-q">بحث</label>
                    <input type="search" class="form-control" id="f-q" name="q"
                           value="{{ $search }}" placeholder="اسمُ العمليّة أو رمزُها">
                </div>

                <div class="f-actions d-flex gap-2">
                    <button class="btn btn-primary" type="submit">
                        <i class="tio-filter-list"></i> تطبيق
                    </button>
                    @if($search !== '' || $category !== '')
                        <a class="btn btn-outline-secondary" href="{{ route('admin.amial.fees.index', ['zone' => $zone]) }}">
                            <i class="tio-clear"></i> مسح
                        </a>
                    @endif
                    @if($canWrite)
                        <a class="btn btn-warning" href="{{ route('admin.amial.fees.create', ['zone' => $zone]) }}">
                            <i class="tio-add"></i> نسخةٌ جديدة
                        </a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    {{-- ══ الجدول ══ --}}
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h5 class="card-header-title mb-0">خطط الرسوم</h5>
            <span class="badge bg-light text-dark">{{ count($rows) }} سطراً</span>
            <span class="badge bg-light text-dark">منطقة: {{ \App\Services\ZonePolicyService::zoneNameAr($zone) }}</span>
        </div>

        <div class="fee-scroll">
            <table class="table table-borderless table-thead-bordered table-align-middle mb-0">
                <thead class="thead-light">
                <tr>
                    <th>العمليّة</th>
                    <th>تُطبَّق على</th>
                    <th>الحالة</th>
                    <th>الرسم</th>
                    <th>الحدّان</th>
                    <th>حصّةُ الوكيل</th>
                    <th>يتحمّلها</th>
                    <th>النسخة</th>
                    <th class="text-center">إجراءات</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rows as $r)
                    @php($op = $r['op'])
                    @php($s = $r['scheme'])
                    <tr>
                        <td>
                            <span class="fw-bold">{{ $op->labelAr }}</span><br>
                            <small class="text-muted font-monospace">{{ $op->code }}</small>
                            <small class="text-muted">· {{ \App\Support\Fees\FeeOperationRegistry::categoryLabel($op->category) }}</small>
                        </td>

                        <td>
                            <span class="badge bg-light text-dark">
                                @switch($r['actor'])
                                    @case('customer') عميل @break
                                    @case('merchant') تاجر @break
                                    @case('agent') وكيل @break
                                    @default {{ $r['actor'] }}
                                @endswitch
                            </span>
                        </td>

                        <td>
                            {{-- AMIAL-FEE-PLAN-001 — استثناءاتُ الباقات تُقال.
                                 فسطرٌ يعرض سعراً واحداً وتحته نسخةُ باقةٍ
                                 يُقرأ أنّ السعرَ واحدٌ للجميع، **وهو ليس كذلك**. --}}
                            @if(!empty($r['plan_overrides']))
                                <div class="mb-1">
                                    @foreach($r['plan_overrides'] as $pl)
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"
                                              title="نسخةٌ خاصّةٌ بهذه الباقة تسبق السعرَ العامّ">
                                            {{ \App\Support\Access\AccessConstants::PLAN_LABELS[$pl] ?? $pl }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            @switch($r['state'])
                                @case('priced')
                                    <span class="fee-state s-priced">مسعَّرة</span>
                                    @break
                                @case('zero')
                                    <span class="fee-state s-zero">مجّانيّة بقرار</span>
                                    @break
                                @case('missing')
                                    <span class="fee-state s-missing">بلا تسعيرة</span>
                                    @break
                                @default
                                    <span class="fee-state s-not_wired"
                                          title="{{ $op->notWiredReason }}">غيرُ موصولة</span>
                            @endswitch
                        </td>

                        <td>
                            @if($s)
                                <span class="money">
                                    @if($s->fee_type !== 'fixed'){{ rtrim(rtrim((string) $s->percent_rate, '0'), '.') }}%@endif
                                    @if($s->fee_type === 'percent_plus_fixed') + @endif
                                    @if($s->fee_type !== 'percent'){{ rtrim(rtrim((string) $s->fixed_amount, '0'), '.') }}@endif
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        <td>
                            @if($s)
                                <small class="money">
                                    {{ $s->min_fee !== null ? rtrim(rtrim((string) $s->min_fee, '0'), '.') : '—' }}
                                    /
                                    {{ $s->max_fee !== null ? rtrim(rtrim((string) $s->max_fee, '0'), '.') : '∞' }}
                                </small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        <td>
                            @if(! $op->agentCommission)
                                <small class="text-muted" title="لا وكيلَ في هذه العمليّة">لا تنطبق</small>
                            @elseif($s && (\App\Services\MoneyService::gt((string) $s->agent_commission_percent, '0')
                                        || \App\Services\MoneyService::gt((string) $s->agent_commission_fixed, '0')))
                                <span class="badge bg-light text-dark money">
                                    {{ rtrim(rtrim((string) $s->agent_commission_percent, '0'), '.') }}%
                                    @if(\App\Services\MoneyService::gt((string) $s->agent_commission_fixed, '0'))
                                        + {{ rtrim(rtrim((string) $s->agent_commission_fixed, '0'), '.') }}
                                    @endif
                                </span>
                            @else
                                <small class="text-muted">صفر</small>
                            @endif
                        </td>

                        <td>
                            @if($s)
                                <small>
                                    @switch($s->bearer)
                                        @case('sender') المرسِل @break
                                        @case('receiver') المستلِم @break
                                        @case('merchant') التاجر @break
                                        @default {{ $s->bearer }}
                                    @endswitch
                                </small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        <td>
                            @if($s)
                                <span class="font-monospace fw-bold">v{{ $s->version }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{--
                            **§8 — قائمةُ إجراءاتٍ لا صفُّ أزرار.**

                            وكان التعطيلُ خلف `confirm()` من المتصفّح: نافذةٌ
                            بلا هويّة، لا تقبل سبباً، ونصُّها إنجليزيٌّ في
                            شاشةٍ عربيّة. **وقرارٌ يجعل عمليّةً مجّانيّةً
                            لا يُؤخَذ بضغطةِ «OK».**
                        --}}
                        <td class="text-center">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                        data-bs-toggle="dropdown" aria-expanded="false"
                                        aria-label="إجراءات {{ $op->labelAr }}">
                                    <i class="tio-more-vertical"></i>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">
                                    @if($op->isLive() && $canWrite)
                                        <li>
                                            <a class="dropdown-item"
                                               href="{{ route('admin.amial.fees.create', ['code' => $op->code, 'zone' => $zone, 'applies_to' => $r['actor']]) }}">
                                                <i class="tio-edit"></i>
                                                {{ $s ? 'نسخةٌ جديدة (تُلغي الحاليّة)' : 'ضبطُ التسعيرة' }}
                                            </a>
                                        </li>
                                    @endif

                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.amial.fees.history', $op->code) }}">
                                            <i class="tio-history"></i> سجلُّ النسخ
                                        </a>
                                    </li>

                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.amial.fees.operations') }}#op-{{ $op->code }}">
                                            <i class="tio-info-outined"></i> تفاصيلُ العمليّة
                                        </a>
                                    </li>

                                    @if($s && $canWrite)
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button type="button" class="dropdown-item text-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deactivate-{{ $s->id }}">
                                                <i class="tio-clear"></i> تعطيلُ التسعيرة
                                            </button>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <div class="fee-empty">
                                <i class="tio-search"></i>
                                <div class="fw-bold">لا عمليّةَ تطابق البحث</div>
                                <div class="small">جرّب تصنيفاً آخر، أو امسح المرشِّحات.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted mt-3 small">
        <i class="tio-info-outined"></i>
        التعديلُ يُنشئ <strong>نسخةً جديدة</strong> تُلغي السابقة. ولا تُحذف نسخةٌ أبداً —
        فكلُّ عمليّةٍ تاريخيّةٍ تبقى مفسَّرةً بالنسخة التي طُبّقت عليها فعلاً.
    </p>

    {{-- ══ نوافذُ التعطيل — خارج الجدول لئلّا يبتلعها `overflow` ══ --}}
    @foreach($rows as $r)
        @php($s = $r['scheme'])
        @if($s && $canWrite)
            <div class="modal fade" id="deactivate-{{ $s->id }}" tabindex="-1"
                 aria-labelledby="deactivate-{{ $s->id }}-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content" method="POST"
                          action="{{ route('admin.amial.fees.deactivate', $s->id) }}">
                        @csrf

                        <div class="modal-header">
                            <h5 class="modal-title" id="deactivate-{{ $s->id }}-title">
                                تعطيلُ تسعيرة «{{ $r['op']->labelAr }}»
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                        </div>

                        <div class="modal-body">
                            <div class="alert alert-warning small mb-3">
                                <strong>بعد التعطيل تمرّ هذه العمليّة بلا رسم</strong> حتّى تُضبط نسخةٌ جديدة.
                                وإن كان القصدُ جعلَها مجّانيّةً بقرار، فالأصحُّ نسخةٌ صريحةٌ بصفر —
                                فتبقى مقروءةً «قرارٌ» لا «إعدادٌ مفقود».
                            </div>

                            <dl class="row small mb-3">
                                <dt class="col-5">النسخة</dt>
                                <dd class="col-7 font-monospace">v{{ $s->version }}</dd>
                                <dt class="col-5">المنطقة</dt>
                                <dd class="col-7">{{ \App\Services\ZonePolicyService::zoneNameAr($s->zone_code) }}</dd>
                                <dt class="col-5">تُطبَّق على</dt>
                                <dd class="col-7">{{ $s->applies_to }}</dd>
                            </dl>

                            <label class="form-label" for="reason-{{ $s->id }}">
                                سببُ التعطيل <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="reason-{{ $s->id }}" name="reason"
                                      rows="2" minlength="5" required
                                      placeholder="مثال: قرارُ الإدارة بإعفاء التحويلات الداخليّة حتّى نهاية الربع"></textarea>
                            <div class="form-text">يُحفَظ في سجلّ التغييرات باسمك وتاريخه.</div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">تراجُع</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="tio-clear"></i> تأكيدُ التعطيل
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach

</div>
@endsection
