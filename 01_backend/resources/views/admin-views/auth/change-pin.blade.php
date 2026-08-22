@extends('layouts.admin.app')

@section('title', 'تغيير رمز الدخول')

@section('content')
<div class="content container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">

            <div class="card mt-5">
                <div class="card-body">

                    <div class="text-center mb-4">
                        <i class="tio-lock" style="font-size:32px"></i>
                        <h3 class="mt-2 mb-1">غيّر رمز الدخول</h3>
                        {{-- **والسببُ يُقال** — حاجزٌ لا يشرح نفسَه يُقرأ عطلاً. --}}
                        <p class="text-muted small mb-0">
                            رمزُ دخولك أوّليٌّ ويعرفه من أصدره.
                            <strong>ولا تُفتَح لك صفحةٌ حتّى تغيّره.</strong>
                        </p>
                    </div>

                    @if(session('warning'))
                        <div class="alert alert-warning">{{ session('warning') }}</div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger">
                            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.auth.pin.update') }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label" for="current-pin">الرمز الحاليّ</label>
                            <input id="current-pin" type="password" name="current_pin"
                                   class="form-control text-center" inputmode="numeric"
                                   maxlength="4" pattern="\d{4}" required autofocus
                                   autocomplete="current-password"
                                   data-testid="current-pin">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="new-pin">الرمز الجديد — أربعةُ أرقام</label>
                            <input id="new-pin" type="password" name="new_pin"
                                   class="form-control text-center" inputmode="numeric"
                                   maxlength="4" pattern="\d{4}" required
                                   autocomplete="new-password"
                                   data-testid="new-pin">
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="new-pin-confirm">أعد الرمز الجديد</label>
                            <input id="new-pin-confirm" type="password" name="new_pin_confirmation"
                                   class="form-control text-center" inputmode="numeric"
                                   maxlength="4" pattern="\d{4}" required
                                   autocomplete="new-password"
                                   data-testid="new-pin-confirm">
                        </div>

                        <button type="submit" class="btn btn-primary w-100"
                                data-testid="submit-pin-change">
                            حفظُ الرمز الجديد
                        </button>
                    </form>

                    {{-- **ومخرجٌ دائماً.** حاجزٌ بلا مخرجٍ سجن: من نسي رمزَه
                         الحاليَّ يجب أن يستطيع الخروجَ ليطلب إعادةَ تعيينه. --}}
                    <div class="text-center mt-3">
                        <a href="{{ route('admin.auth.logout') }}" class="small text-muted">
                            نسيتُ الرمز الحاليّ — خروجٌ وطلبُ إعادة تعيين
                        </a>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>
@endsection
