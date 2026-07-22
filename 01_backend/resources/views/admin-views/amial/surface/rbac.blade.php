@extends('layouts.admin.app')
@section('title', translate('صلاحيات RBAC'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-1" style="color:#053391">🛡️ {{ translate('الصلاحيات — المدراء المعيَّنون') }}</h4>
    <small class="text-muted">{{ translate('إجمالي موظفي نقاط البيع') }}: {{ $staffCount }}</small>
    <div class="card border-0 shadow-sm mt-3" style="border-radius:16px"><div class="card-body">
        <table class="table align-middle">
            <thead><tr><th>{{ translate('الموظف') }}</th><th>{{ translate('التاجر') }}</th><th>{{ translate('الأدوار') }}</th><th>{{ translate('نشط') }}</th></tr></thead>
            <tbody>
            @forelse($managers as $m)
                <tr>
                    <td><b>{{ $m->display_name }}</b><br><small class="text-muted">POS {{ $m->pos_number }}</small></td>
                    <td>{{ $m->merchant?->f_name }} {{ $m->merchant?->l_name }}</td>
                    <td>
                        @if(in_array('operations_manager', $m->permissions ?? [])) <span class="badge" style="background:#053391">مدير عمليات</span> @endif
                        @if(in_array('financial_manager', $m->permissions ?? [])) <span class="badge" style="background:#B8860B">مدير مالي</span> @endif
                    </td>
                    <td>{!! $m->is_active ? '✅' : '⛔' !!}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">{{ translate('لا مدراء معيَّنون بعد — يعيّنهم التاجر من شاشة الموظفين') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div></div>
</div>
@endsection
