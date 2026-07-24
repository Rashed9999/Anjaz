@extends('layouts.admin.app')
{{-- AMIAL-EMONEY-001: رصيد المنصّة — نظرة عامّة + إنشاء رصيد (كانت الواجهة مفقودة). --}}
@section('title', translate('رصيد المنصّة'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:#053391">🏦 {{ translate('رصيد المنصّة (E-Money)') }}</h4>

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
                        ⚠️ {{ translate('هذه العملية تُصدِر رصيداً جديداً إلى خزينة المنصّة (معاملة إيداع للحساب الإداري). استخدمها بحذر — تُسجَّل كاملةً في سجلّ المعاملات.') }}
                    </div>
                    <form method="POST" action="{{ route('admin.emoney.store') }}" onsubmit="return confirm('{{ translate('تأكيد إنشاء الرصيد؟') }}')">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">{{ translate('المبلغ') }} (ر.ي)</label>
                            <input type="number" name="amount" step="0.01" min="0" class="form-control form-control-lg"
                                   required placeholder="0.00" style="font-weight:bold;color:#053391">
                        </div>
                        <button type="submit" class="btn w-100 text-white" style="background:#053391">
                            <i class="tio-add-circle"></i> {{ translate('إنشاء الرصيد') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
