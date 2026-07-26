@extends('layouts.admin.app')
{{-- AMIAL-CONTENT-001: إدارة الأسئلة الشائعة + الفئات (كانت الواجهة مفقودة). --}}
@section('title', translate('الأسئلة الشائعة'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h4 class="fw-bold m-0" style="color:#053391">❓ {{ translate('الأسئلة الشائعة') }}</h4>
        <a href="{{ route('admin.faq.create') }}" class="btn btn-sm text-white" style="background:#053391">
            <i class="tio-add"></i> {{ translate('سؤال جديد') }}
        </a>
    </div>

    <div class="row">
        {{-- الفئات --}}
        <div class="col-lg-4 mb-3">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0"><span class="fw-bold">{{ translate('الفئات') }}</span></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.faq.category.store') }}" class="d-flex gap-2 mb-3">
                        @csrf
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="{{ translate('اسم الفئة') }}" required>
                        <button class="btn btn-sm text-white" style="background:#053391">{{ translate('إضافة') }}</button>
                    </form>
                    <ul class="list-group list-group-flush">
                        @forelse($categories as $cat)
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>{{ $cat->name }}</span>
                                <span class="badge {{ $cat->status ? 'bg-soft-success text-success' : 'bg-soft-secondary text-secondary' }}">
                                    {{ $cat->status ? translate('مفعّل') : translate('معطّل') }}
                                </span>
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">{{ translate('لا توجد فئات') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

        {{-- الأسئلة --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <span class="fw-bold">{{ translate('الأسئلة') }}</span>
                    <form method="GET" action="{{ route('admin.faq.index') }}" class="d-flex gap-2" style="max-width:260px">
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="{{ translate('بحث...') }}">
                        <button class="btn btn-sm btn-outline-primary">{{ translate('بحث') }}</button>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-align-middle m-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>{{ translate('السؤال') }}</th>
                                    <th>{{ translate('الفئة') }}</th>
                                    <th>{{ translate('الحالة') }}</th>
                                    <th>{{ translate('إجراءات') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($faqs as $key => $faq)
                                    <tr id="faq-row-{{ $faq->id }}">
                                        <td>{{ $faqs->firstItem() + $key }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($faq->question, 70) }}</td>
                                        <td><span class="badge bg-soft-info text-info">{{ $faq->faqCategory->name ?? '—' }}</span></td>
                                        <td>
                                            <button onclick="faqToggle({{ $faq->id }}, {{ $faq->status ? 0 : 1 }})"
                                                    class="badge border-0 {{ $faq->status ? 'bg-soft-success text-success' : 'bg-soft-secondary text-secondary' }}">
                                                {{ $faq->status ? translate('مفعّل') : translate('معطّل') }}
                                            </button>
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.faq.details', ['id' => $faq->id]) }}" class="btn btn-sm btn-outline-secondary"><i class="tio-visible"></i></a>
                                            <a href="{{ route('admin.faq.edit', ['id' => $faq->id]) }}" class="btn btn-sm btn-outline-primary"><i class="tio-edit"></i></a>
                                            <button onclick="faqDelete({{ $faq->id }})" class="btn btn-sm btn-outline-danger"><i class="tio-delete"></i></button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center py-5 text-muted">{{ translate('لا توجد أسئلة') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($faqs->hasPages())
                    <div class="card-footer border-0 d-flex justify-content-center">{!! $faqs->links() !!}</div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
    function faqToggle(id, status) {
        // AMIAL-CSRF-001: كان GET — تبديل حالة سؤال بطلب لا يحمل رمزاً.
        fetch("{{ url('admin/faq/status') }}/" + id + "/" + status, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
        }).then(function () { location.reload(); });
    }
    function faqDelete(id) {
        if (!confirm('{{ translate('حذف هذا السؤال؟') }}')) return;
        fetch("{{ url('admin/faq/delete') }}/" + id, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
        }).then(function () {
            var row = document.getElementById('faq-row-' + id);
            if (row) row.remove();
        });
    }
</script>
@endsection
