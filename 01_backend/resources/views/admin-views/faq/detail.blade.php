@extends('layouts.admin.app')
{{-- AMIAL-CONTENT-001: تفاصيل سؤال شائع. --}}
@section('title', translate('تفاصيل السؤال'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:#053391">👁️ {{ translate('تفاصيل السؤال') }}</h4>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-body">
                    <div class="mb-2">
                        <span class="badge bg-soft-info text-info">{{ $faq->faqCategory->name ?? translate('بدون فئة') }}</span>
                        <span class="badge {{ $faq->status ? 'bg-soft-success text-success' : 'bg-soft-secondary text-secondary' }}">
                            {{ $faq->status ? translate('مفعّل') : translate('معطّل') }}
                        </span>
                    </div>
                    <h5 class="fw-bold mt-3">{{ $faq->question }}</h5>
                    <p class="text-muted" style="white-space:pre-line">{{ $faq->answer }}</p>
                    <div class="d-flex gap-2 mt-4">
                        <a href="{{ route('admin.faq.edit', ['id' => $faq->id]) }}" class="btn text-white" style="background:#053391">{{ translate('تعديل') }}</a>
                        <a href="{{ route('admin.faq.index') }}" class="btn btn-outline-secondary">{{ translate('رجوع') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
