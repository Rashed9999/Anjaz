@extends('layouts.admin.app')
{{-- AMIAL-CONTENT-001: تعديل/إعادة إرسال إشعار. --}}
@section('title', translate('تعديل إشعار'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:#053391">✏️ {{ translate('تعديل الإشعار') }}</h4>

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.notification.update', ['id' => $notification->id]) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="text-center mb-3">
                            <img src="{{ $notification->image_fullpath }}" alt="n"
                                 style="width:90px;height:90px;object-fit:cover;border-radius:12px">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('العنوان') }}</label>
                            <input type="text" name="title" class="form-control" required value="{{ $notification->title }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('النص') }}</label>
                            <textarea name="description" rows="3" class="form-control" required>{{ $notification->description }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('المستلمون') }}</label>
                            <select name="receiver" class="form-control">
                                <option value="all"       {{ $notification->receiver === 'all' ? 'selected' : '' }}>{{ translate('الجميع') }}</option>
                                <option value="customers" {{ $notification->receiver === 'customers' ? 'selected' : '' }}>{{ translate('العملاء') }}</option>
                                <option value="agents"    {{ $notification->receiver === 'agents' ? 'selected' : '' }}>{{ translate('الوكلاء') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('استبدال الصورة') }} <small class="text-muted">({{ translate('اختياري') }})</small></label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                        </div>
                        <div class="alert alert-warning small mb-3">
                            {{ translate('عند الحفظ سيُعاد إرسال الإشعار كإشعار دفع للفئة المختارة.') }}
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn text-white flex-grow-1" style="background:#053391">{{ translate('حفظ وإعادة الإرسال') }}</button>
                            <a href="{{ route('admin.notification.add-new') }}" class="btn btn-outline-secondary">{{ translate('رجوع') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
