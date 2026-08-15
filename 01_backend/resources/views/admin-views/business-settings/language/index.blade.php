@extends('layouts.admin.app')
{{-- AMIAL-LANG-ADMIN-001: إدارة اللغات (كانت الواجهة مفقودة). العربية أساسية. --}}
@section('title', translate('اللغات'))
@section('content')
@php $languages = \App\CentralLogics\Helpers::get_business_settings('language') ?? []; @endphp
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:var(--amial-primary)">🌐 {{ translate('إدارة اللغات') }}</h4>
    <div class="alert alert-info small">
        {{ translate('العربية هي اللغة الأساسية لأميال باي، والإنجليزية لغة ثانوية اختيارية.') }}
    </div>

    <div class="row">
        {{-- إضافة لغة --}}
        <div class="col-lg-4 mb-3">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0"><span class="fw-bold">{{ translate('إضافة لغة') }}</span></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.business-settings.language.add-new') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">{{ translate('اسم اللغة') }}</label>
                            <input type="text" name="name" class="form-control" required placeholder="{{ translate('مثال: French') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('رمز اللغة (ISO)') }}</label>
                            <input type="text" name="code" class="form-control" required maxlength="5" placeholder="fr">
                        </div>
                        <button type="submit" class="btn w-100 text-white" style="background:var(--amial-primary)">{{ translate('إضافة') }}</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- قائمة اللغات --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0"><span class="fw-bold">{{ translate('اللغات المتاحة') }}</span></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-align-middle m-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>{{ translate('اللغة') }}</th>
                                    <th>{{ translate('الرمز') }}</th>
                                    <th>{{ translate('الحالة') }}</th>
                                    <th>{{ translate('الافتراضية') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($languages as $i => $lang)
                                    <tr>
                                        <td>{{ $i + 1 }}</td>
                                        <td class="fw-semibold">{{ ucfirst($lang['name'] ?? '—') }}</td>
                                        <td style="font-family:monospace">{{ $lang['code'] ?? '' }}</td>
                                        <td>
                                            <button onclick="langToggle('{{ $lang['code'] }}')"
                                                    class="badge border-0 {{ ($lang['status'] ?? 0) ? 'bg-soft-success text-success' : 'bg-soft-secondary text-secondary' }}">
                                                {{ ($lang['status'] ?? 0) ? translate('مفعّلة') : translate('معطّلة') }}
                                            </button>
                                        </td>
                                        <td>
                                            @if(!empty($lang['default']))
                                                <span class="badge bg-soft-primary text-primary">★ {{ translate('افتراضية') }}</span>
                                            @else
                                                <button type="button" onclick="langDefault('{{ $lang['code'] }}')"
                                                        class="btn btn-sm btn-outline-primary">{{ translate('اجعلها افتراضية') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center py-4 text-muted">{{ translate('لا توجد لغات مُعرّفة') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // AMIAL-CSRF-001: تفعيل لغة وتعيينها افتراضيةً تغييران في حالة النظام،
    // فيُرسَلان بـ POST مع رمز CSRF. كانا GET — ووسم صورة في أي صفحة يفتحها
    // مديرٌ مسجَّل الدخول كان يكفي لتنفيذهما بجلسته بلا علمه.
    function langPost(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        }).then(function () { location.reload(); });
    }

    function langToggle(code) {
        langPost("{{ route('admin.business-settings.language.update-status') }}?code=" + encodeURIComponent(code));
    }

    function langDefault(code) {
        langPost("{{ route('admin.business-settings.language.update-default-status') }}?code=" + encodeURIComponent(code));
    }
</script>
@endsection
