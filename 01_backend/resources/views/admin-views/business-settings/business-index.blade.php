@extends('layouts.admin.app')
{{-- AMIAL-BIZ-SETUP-001: الإعدادات العامة (أعيد بناؤها بعد فقدان واجهة 6cash) --}}
@section('title', translate('إعدادات الأعمال'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:var(--amial-primary)">🏢 {{ translate('إعدادات الأعمال') }}</h4>
    @include('admin-views.business-settings.partials._tabs', ['tab' => 'business'])
    @php $bs = fn($k, $d = '') => \App\CentralLogics\Helpers::get_business_settings($k) ?? $d; @endphp
    <form method="POST" action="{{ route('admin.business-settings.business-setup') }}" enctype="multipart/form-data">
        @csrf
        <div class="card border-0 shadow-sm" style="border-radius:16px">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">{{ translate('اسم المنصّة') }}</label>
                        <input name="business_name" class="form-control" value="{{ $bs('business_name', 'Amial Pay') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('العملة') }}</label>
                        <input name="currency" class="form-control" value="{{ $bs('currency', 'YER') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('المنطقة الزمنية') }}</label>
                        <input name="timezone" class="form-control" value="{{ $bs('timezone', 'Asia/Aden') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('حدّ الترقيم (صفوف/صفحة)') }}</label>
                        <input name="pagination_limit" type="number" class="form-control" value="{{ $bs('pagination_limit', 25) }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('الشعار (اختياري)') }}</label>
                        <input name="logo" type="file" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">{{ translate('الأيقونة Favicon (اختياري)') }}</label>
                        <input name="favicon" type="file" class="form-control"></div>
                </div>
                <button class="btn text-white mt-4" style="background:var(--amial-primary)">{{ translate('حفظ الإعدادات') }}</button>
            </div>
        </div>
    </form>
</div>
@endsection
