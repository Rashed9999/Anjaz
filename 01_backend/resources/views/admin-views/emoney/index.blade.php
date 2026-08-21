@extends('layouts.admin.app')
{{-- AMIAL-EMONEY-001: رصيد المنصّة — نظرة عامّة + إنشاء رصيد (كانت الواجهة مفقودة). --}}
@section('title', translate('رصيد المنصّة'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:var(--amial-primary)">🏦 {{ translate('رصيد المنصّة (E-Money)') }}</h4>

    {{-- نظرة عامّة --}}
    @php
        $cards = [
            ['label'=>'إجمالي الرصيد المُصدَر','value'=>$balance['total_balance'] ?? 0,'icon'=>'🏦','color'=>'#053391'],
            ['label'=>'الأموال المتداولة (محافظ المستخدمين)','value'=>$balance['used_balance'] ?? 0,'icon'=>'💳','color'=>'#1D4FB8'],
            ['label'=>'رصيد الخزينة (غير المتداول)','value'=>$balance['unused_balance'] ?? 0,'icon'=>'💰','color'=>'#12694E'],
            ['label'=>'أرباح الرسوم','value'=>$balance['total_earned'] ?? 0,'icon'=>'📈','color'=>'#B8860B'],
        ];
    @endphp
    <div class="row g-3 mb-4">
        @foreach($cards as $c)
        <div class="col-6 col-md-3">
            <div class="card h-100 border-0 shadow-sm" style="border-radius:14px;border-bottom:3px solid {{ $c['color'] }}">
                <div class="card-body py-3">
                    <span style="font-size:20px">{{ $c['icon'] }}</span>
                    <div class="fw-bold mt-1" style="font-size:18px;color:{{ $c['color'] }}">
                        {{ \App\CentralLogics\Helpers::set_symbol((float)$c['value']) }}
                    </div>
                    <small class="text-muted" style="font-size:11px">{{ translate($c['label']) }}</small>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- إنشاء رصيد المنصّة --}}
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0"><span class="fw-bold">{{ translate('إنشاء / شحن رصيد المنصّة') }}</span></div>
                <div class="card-body">
                    <div class="alert alert-warning small">
                        ⚠️ {{ translate('هذه العملية تُصدر رصيداً مقابل احتياطي خزينة موثق. المرجع والسبب إلزاميان، والقيد المزدوج وسجل التدقيق يُنشآن معاً.') }}
                    </div>
                    <form method="POST" action="{{ route('admin.emoney.store') }}" onsubmit="return confirm('{{ translate('تأكيد تسجيل طلب الإصدار؟ سيُنشأ قيدٌ مزدوجٌ وسجلُّ تدقيق.') }}')">
                        @csrf
                        {{-- AMIAL-TREASURY-SOURCE-001 — **يبدأ الطلبُ من
                             «أين وصل المالُ الحقيقيّ؟»**، والطرفُ المقابلُ
                             في الدفتر يتبع هذا الاختيار. وكلُّ إصدارٍ كان
                             يُقيَّد «نقدٌ في الخزينة» — كذبةٌ في ثلاثةٍ من
                             خمسة، ومن جرَد الخزنةَ وجدها ناقصةً بمقدار كلّ
                             إيداعٍ بنكيٍّ منذ النشأة. --}}
                        <div class="mb-3">
                            <label class="form-label">{{ translate('أين وصل المال الحقيقي؟') }}</label>
                            <select name="funding_source" class="form-select" required
                                    data-testid="emoney-funding-source">
                                @foreach (\App\Services\PlatformTreasuryService::FUNDING_SOURCES as $key => $src)
                                    <option value="{{ $key }}">{{ $src['label'] }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                {{ translate('الطرف المقابل في الدفتر يتبع هذا الاختيار — نقد الخزينة، أو رصيد بنكي، أو حقوق ملكية، أو حساب معلّق.') }}
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('المبلغ') }} (ر.ي)</label>
                            <input type="number" name="amount" step="0.01" min="0" class="form-control form-control-lg"
                                   required placeholder="0.00" style="font-weight:bold;color:var(--amial-primary)">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('مرجع الإثبات / سند التوريد') }}</label>
                            <input type="text" name="reference" maxlength="120" class="form-control" required
                                   placeholder="TREASURY-2026-0001">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('سبب الإصدار') }}</label>
                            <textarea name="reason" maxlength="500" class="form-control" rows="2" required
                                      placeholder="توريد نقد موثق إلى خزينة المنصة"></textarea>
                        </div>
                        <button type="submit" class="btn w-100 text-white" style="background:var(--amial-primary)">
                            <i class="tio-add-circle"></i> {{ translate('سجّل طلب الإصدار') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
