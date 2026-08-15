@extends('layouts.admin.app')
{{-- AMIAL-BIZ-SETUP-001: الرسوم والعمولات (أعيد بناؤها بعد فقدان واجهة 6cash) --}}
@section('title', translate('الرسوم والعمولات'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:var(--amial-primary)">💰 {{ translate('الرسوم والعمولات') }}</h4>
    @include('admin-views.business-settings.partials._tabs', ['tab' => 'charge'])
    @php $bs = fn($k, $d = 0) => \App\CentralLogics\Helpers::get_business_settings($k) ?? $d; @endphp
    <form method="POST" action="{{ route('admin.business-settings.charge-setup') }}">
        @csrf @method('PUT')
        <div class="card border-0 shadow-sm" style="border-radius:16px">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">{{ translate('رسم التحويل الثابت') }}</label>
                        <input name="sendmoney_charge_flat" type="number" step="0.01" class="form-control" value="{{ $bs('sendmoney_charge_flat') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('رسم السحب النقدي %') }}</label>
                        <input name="cashout_charge_percent" type="number" step="0.01" class="form-control" value="{{ $bs('cashout_charge_percent') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('عمولة الوكيل %') }}</label>
                        <input name="agent_commission_percent" type="number" step="0.01" class="form-control" value="{{ $bs('agent_commission_percent') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('رسم طلب السحب %') }}</label>
                        <input name="withdraw_charge_percent" type="number" step="0.01" class="form-control" value="{{ $bs('withdraw_charge_percent') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('حدّ الأرقام المفضّلة') }}</label>
                        <input name="favorite_number_limit" type="number" class="form-control" value="{{ $bs('favorite_number_limit', 10) }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('تفعيل الأرقام المفضّلة') }}</label>
                        <select name="favorite_number_status" class="form-control">
                            <option value="1" {{ $bs('favorite_number_status') ? 'selected' : '' }}>{{ translate('مفعّل') }}</option>
                            <option value="0" {{ !$bs('favorite_number_status') ? 'selected' : '' }}>{{ translate('معطّل') }}</option>
                        </select></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('خصم تحويل للمفضّلة %') }}</label>
                        <input name="favorite_number_send_money_charge_discount" type="number" step="0.01" class="form-control" value="{{ $bs('favorite_number_send_money_charge_discount') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('خصم سحب للمفضّلة %') }}</label>
                        <input name="favorite_number_cash_out_charge_discount" type="number" step="0.01" class="form-control" value="{{ $bs('favorite_number_cash_out_charge_discount') }}"></div>
                </div>
                <button class="btn text-white mt-4" style="background:var(--amial-primary)">{{ translate('حفظ الرسوم') }}</button>
            </div>
        </div>
    </form>
</div>
@endsection
