@extends('layouts.admin.app')
{{-- AMIAL-CONTENT-001: تعديل بانر. --}}
@section('title', translate('تعديل بانر'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:#053391">✏️ {{ translate('تعديل البانر') }}</h4>

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.banner.update', ['id' => $banner->id]) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="text-center mb-3">
                            <img src="{{ $banner->image_fullpath }}" alt="banner"
                                 style="max-width:100%;height:120px;object-fit:cover;border-radius:12px">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('العنوان') }}</label>
                            <input type="text" name="title" class="form-control" required value="{{ $banner->title }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('الرابط عند الضغط') }}</label>
                            <input type="url" name="url" class="form-control" required value="{{ $banner->url }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('الفئة المستهدفة') }}</label>
                            <select name="receiver" class="form-control">
                                <option value="all"       {{ $banner->receiver === 'all' ? 'selected' : '' }}>{{ translate('الجميع') }}</option>
                                <option value="customers" {{ $banner->receiver === 'customers' ? 'selected' : '' }}>{{ translate('العملاء') }}</option>
                                <option value="agents"    {{ $banner->receiver === 'agents' ? 'selected' : '' }}>{{ translate('الوكلاء') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('استبدال الصورة') }} <small class="text-muted">({{ translate('اختياري') }})</small></label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn text-white flex-grow-1" style="background:#053391">{{ translate('حفظ التعديلات') }}</button>
                            <a href="{{ route('admin.banner.index') }}" class="btn btn-outline-secondary">{{ translate('رجوع') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
