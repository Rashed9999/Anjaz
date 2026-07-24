@extends('layouts.admin.app')
{{-- AMIAL-CONTENT-001: إدارة بانرات الصفحة الرئيسية (كانت الواجهة مفقودة). --}}
@section('title', translate('البانرات'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:#053391">🖼️ {{ translate('بانرات الرئيسية') }}</h4>

    <div class="row">
        {{-- نموذج الإضافة --}}
        <div class="col-lg-4 mb-3">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0"><span class="fw-bold">{{ translate('إضافة بانر') }}</span></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.banner.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">{{ translate('العنوان') }}</label>
                            <input type="text" name="title" class="form-control" required value="{{ old('title') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('الرابط عند الضغط') }}</label>
                            <input type="url" name="url" class="form-control" placeholder="https://..." required value="{{ old('url') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('الفئة المستهدفة') }}</label>
                            <select name="receiver" class="form-control">
                                <option value="all">{{ translate('الجميع') }}</option>
                                <option value="customers">{{ translate('العملاء') }}</option>
                                <option value="agents">{{ translate('الوكلاء') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('الصورة') }} <small class="text-muted">(1920×400)</small></label>
                            <input type="file" name="image" class="form-control" accept="image/*" required>
                        </div>
                        <button type="submit" class="btn w-100 text-white" style="background:#053391">
                            {{ translate('حفظ البانر') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- القائمة --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <span class="fw-bold">{{ translate('البانرات الحالية') }}</span>
                    <span class="badge bg-soft-primary text-primary">{{ $banners->total() }}</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-align-middle m-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>{{ translate('الصورة') }}</th>
                                    <th>{{ translate('العنوان') }}</th>
                                    <th>{{ translate('الفئة') }}</th>
                                    <th>{{ translate('الحالة') }}</th>
                                    <th>{{ translate('إجراءات') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($banners as $key => $banner)
                                    <tr>
                                        <td>{{ $banners->firstItem() + $key }}</td>
                                        <td><img src="{{ $banner->image_fullpath }}" alt="banner"
                                                 style="width:90px;height:34px;object-fit:cover;border-radius:6px"></td>
                                        <td>{{ $banner->title }}</td>
                                        <td><span class="badge bg-soft-info text-info">{{ translate($banner->receiver ?? 'all') }}</span></td>
                                        <td>
                                            <a href="{{ route('admin.banner.status', ['id' => $banner->id]) }}"
                                               class="badge {{ $banner->status ? 'bg-soft-success text-success' : 'bg-soft-secondary text-secondary' }}">
                                                {{ $banner->status ? translate('مفعّل') : translate('معطّل') }}
                                            </a>
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.banner.edit', ['id' => $banner->id]) }}"
                                               class="btn btn-sm btn-outline-primary"><i class="tio-edit"></i></a>
                                            <a href="{{ route('admin.banner.delete', ['id' => $banner->id]) }}"
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('{{ translate('حذف هذا البانر؟') }}')"><i class="tio-delete"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center py-5 text-muted">{{ translate('لا توجد بانرات') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($banners->hasPages())
                    <div class="card-footer border-0 d-flex justify-content-center">{!! $banners->links() !!}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
