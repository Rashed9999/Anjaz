{{-- AMIAL-BIZ-SETUP-001: تبويبات إعدادات الأعمال (بأسلوب 6cash) --}}
@php $tab = $tab ?? 'business'; @endphp
<ul class="nav nav-pills mb-4 gap-2" dir="rtl">
    <li class="nav-item"><a class="nav-link {{ $tab == 'business' ? 'active text-white' : '' }}" style="{{ $tab == 'business' ? 'background:var(--amial-primary)' : 'color:var(--amial-primary)' }}" href="{{ route('admin.business-settings.business-setup') }}">{{ translate('الإعدادات العامة') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab == 'charge' ? 'active text-white' : '' }}" style="{{ $tab == 'charge' ? 'background:var(--amial-primary)' : 'color:var(--amial-primary)' }}" href="{{ route('admin.business-settings.charge-setup') }}">{{ translate('الرسوم والعمولات') }}</a></li>
    <li class="nav-item"><a class="nav-link" style="color:var(--amial-primary)" href="{{ route('admin.amial.hub.settings') }}">{{ translate('مفاتيح الميزات') }}</a></li>
    <li class="nav-item"><a class="nav-link" style="color:var(--amial-primary)" href="{{ route('admin.amial.fees.index') }}">{{ translate('لوحة الرسوم والأرباح') }}</a></li>
</ul>
