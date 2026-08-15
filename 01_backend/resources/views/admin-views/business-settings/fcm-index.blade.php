@extends('layouts.admin.app')
{{-- AMIAL-FCM-002: إعداد Firebase (أُعيد بناؤها — كان المسار يشير إلى قالب محذوف) --}}
@section('title', translate('إعداد Firebase'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:var(--amial-primary)">🔥 {{ translate('إعداد Firebase (الإشعارات)') }}</h4>

    {{-- حالة المفتاح الحالي --}}
    <div class="card border-0 shadow-sm mb-3" style="border-radius:16px">
        <div class="card-body">
            @if($fcm['configured'])
                <div class="d-flex align-items-center mb-2">
                    <span class="badge" style="background:#0F9D58">{{ translate('مُهيّأ') }}</span>
                    <span class="mx-2 fw-bold">{{ $fcm['project_id'] }}</span>
                </div>
                <div class="text-muted small">
                    <div>{{ translate('حساب الخدمة') }}: <code>{{ $fcm['client_email'] }}</code></div>
                    <div>{{ translate('بصمة المفتاح') }}: <code>{{ $fcm['key_fingerprint'] }}</code></div>
                    @unless($fcm['has_private_key'])
                        <div class="text-danger mt-1">
                            ⚠️ {{ translate('المفتاح الخاص مفقود أو تالف — أعد لصق الملف.') }}
                        </div>
                    @endunless
                </div>
            @else
                <span class="badge bg-danger">{{ translate('غير مُهيّأ') }}</span>
                <span class="mx-2">{{ translate('لن تصل أي إشعارة حتى تحفظ ملف حساب الخدمة أدناه.') }}</span>
            @endif
        </div>
    </div>

    {{-- حفظ ملف حساب الخدمة --}}
    <form method="POST" action="{{ route('admin.business-settings.update-fcm') }}">
        @csrf
        <div class="card border-0 shadow-sm mb-3" style="border-radius:16px">
            <div class="card-body">
                <label class="form-label fw-bold">{{ translate('محتوى ملف حساب الخدمة (Service account JSON)') }}</label>
                <p class="text-muted small mb-2">
                    {{ translate('من Firebase Console ← ⚙️ Project settings ← تبويب Service accounts ← Generate new private key. افتح الملف المُحمَّل وانسخ محتواه كاملاً من { إلى } والصقه هنا.') }}
                    <br>
                    <span class="text-danger fw-bold">{{ translate('هذا الملف سرّ: من يملكه يرسل إشعارات باسم تطبيقك. لا تضعه في مستودع الشيفرة ولا ترسله في محادثة.') }}</span>
                    <br>
                    {{ translate('اتركه فارغاً إن أردت الحفظ دون تغيير المفتاح المحفوظ.') }}
                </p>
                <textarea name="push_notification_service_file_content" class="form-control" rows="10"
                          dir="ltr" spellcheck="false" placeholder='{"type":"service_account","project_id":"amial-pay", ...}'></textarea>

                <label class="form-label fw-bold mt-3">{{ translate('مفتاح الخادم القديم (اختياري)') }}</label>
                <input name="push_notification_key" type="text" class="form-control" dir="ltr"
                       placeholder="{{ translate('غير مطلوب — أميال باي تستعمل HTTP v1') }}">

                <button class="btn text-white mt-3" style="background:var(--amial-primary)">{{ translate('حفظ') }}</button>
            </div>
        </div>
    </form>

    {{-- اختبار حقيقي --}}
    <form method="POST" action="{{ route('admin.business-settings.test-fcm') }}">
        @csrf
        <div class="card border-0 shadow-sm" style="border-radius:16px">
            <div class="card-body">
                <label class="form-label fw-bold">{{ translate('إرسال إشعارة اختبار') }}</label>
                <p class="text-muted small mb-2">
                    {{ translate('يرسل إشعارة فعلية إلى هاتف مستخدم مسجّل، ويعرض سبب الفشل بدقّة إن فشل: مفتاح خاطئ، أو جهاز بلا رمز، أو رفض من Google.') }}
                </p>
                <div class="row g-2">
                    <div class="col-md-4">
                        <input name="test_phone" type="text" class="form-control" dir="ltr"
                               placeholder="{{ translate('رقم هاتف مستخدم — مثال 770000000') }}">
                    </div>
                    <div class="col-md-3">
                        <button class="btn text-white w-100" style="background:var(--amial-yellow);color:var(--amial-primary)!important">
                            {{ translate('أرسل اختباراً') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
