@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — لوحة الإعدادات (تحكّم بضغطة زر بلا كود).
     كل مفتاح يكتب مباشرة في business_settings ويؤثّر على سلوك النظام فوراً. --}}

@section('title', 'لوحة الإعدادات')

@section('content')
<div class="content container-fluid">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">لوحة الإعدادات</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>

    <div class="alert alert-info small">
        غيّر سلوك المنصّة بضغطة زر دون الحاجة لتعديل الكود. لإيقاف/تشغيل خدمات
        محدّدة أثناء الصيانة استخدم <a href="{{ url('admin/maintenance') }}">وضع الصيانة</a>.
    </div>

    <div class="card stat-card">
        <ul class="list-group list-group-flush" id="flags">
            @php
                $labels = [
                    'phone_verification' => ['title' => 'التحقّق بالهاتف (OTP عند التسجيل)', 'desc' => 'يطلب رمز تحقق عند تسجيل حساب جديد.'],
                    'referral_earning_status' => ['title' => 'مكافآت الإحالة', 'desc' => 'تفعيل عمولة دعوة الأصدقاء.'],
                    'maintenance_mode' => ['title' => 'وضع الصيانة العام', 'desc' => 'إشعار عام بأن النظام تحت الصيانة.'],
                ];
            @endphp
            @foreach($labels as $key => $meta)
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-bold">{{ $meta['title'] }}</div>
                    <div class="small text-muted">{{ $meta['desc'] }}</div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input flag-toggle" type="checkbox" role="switch"
                           data-key="{{ $key }}" style="width:3rem;height:1.5rem"
                           {{ ($flags[$key] ?? '0') === '1' ? 'checked' : '' }}>
                </div>
            </li>
            @endforeach
        </ul>
    </div>
</div>
@endsection

@push('script')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';

    document.querySelectorAll('.flag-toggle').forEach(el => {
        el.addEventListener('change', async () => {
            const key = el.dataset.key;
            const value = el.checked ? '1' : '0';
            try {
                const r = await fetch(`${base}/settings/flag`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    body: JSON.stringify({key, value}),
                });
                const j = await r.json();
                if (!r.ok) throw new Error(j.message || 'خطأ');
                // مؤشّر نجاح بصري بسيط
                el.closest('li').style.background = '#e8f5e9';
                setTimeout(() => { el.closest('li').style.background = ''; }, 800);
            } catch (err) {
                alert(err.message);
                el.checked = !el.checked; // تراجع
            }
        });
    });
})();
</script>
@endpush
