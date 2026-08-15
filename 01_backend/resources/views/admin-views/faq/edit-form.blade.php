@extends('layouts.admin.app')
{{-- AMIAL-CONTENT-001: تعديل سؤال شائع. --}}
@section('title', translate('تعديل سؤال'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:var(--amial-primary)">✏️ {{ translate('تعديل السؤال') }}</h4>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.faq.update', ['id' => $faq->id]) }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">{{ translate('الفئة') }}</label>
                            <select name="category_id" class="form-control">
                                <option value="">{{ translate('بدون فئة') }}</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}" {{ $faq->category_id == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('السؤال') }}</label>
                            <input type="text" name="question" class="form-control" maxlength="255" required value="{{ $faq->question }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('الإجابة') }}</label>
                            <textarea name="answer" rows="5" class="form-control" maxlength="1000" required>{{ $faq->answer }}</textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn text-white flex-grow-1" style="background:var(--amial-primary)">{{ translate('حفظ التعديلات') }}</button>
                            <a href="{{ route('admin.faq.index') }}" class="btn btn-outline-secondary">{{ translate('رجوع') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
