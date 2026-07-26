@extends('layouts.admin.app')
{{-- AMIAL-CONTENT-001: إرسال إشعارات دفع للمستخدمين (كانت الواجهة مفقودة). --}}
@section('title', translate('الإشعارات'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:#053391">🔔 {{ translate('إشعارات الدفع') }}</h4>

    <div class="row">
        {{-- نموذج الإرسال --}}
        <div class="col-lg-4 mb-3">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0"><span class="fw-bold">{{ translate('إرسال إشعار جديد') }}</span></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.notification.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">{{ translate('العنوان') }}</label>
                            <input type="text" name="title" class="form-control" required value="{{ old('title') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('النص') }}</label>
                            <textarea name="description" rows="3" class="form-control" required>{{ old('description') }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('المستلمون') }}</label>
                            <select name="receiver" class="form-control" required>
                                <option value="all">{{ translate('الجميع') }}</option>
                                <option value="customers">{{ translate('العملاء') }}</option>
                                <option value="agents">{{ translate('الوكلاء') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ translate('الصورة') }}</label>
                            <input type="file" name="image" class="form-control" accept="image/*" required>
                        </div>
                        <button type="submit" class="btn w-100 text-white" style="background:#053391">
                            <i class="tio-send"></i> {{ translate('إرسال الإشعار') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- القائمة --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <span class="fw-bold">{{ translate('الإشعارات المُرسلة') }}</span>
                    <form method="GET" action="{{ route('admin.notification.add-new') }}" class="d-flex gap-2" style="max-width:260px">
                        <input type="text" name="search" value="{{ $search ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('بحث...') }}">
                        <button class="btn btn-sm btn-outline-primary">{{ translate('بحث') }}</button>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-align-middle m-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>{{ translate('الصورة') }}</th>
                                    <th>{{ translate('العنوان') }}</th>
                                    <th>{{ translate('المستلمون') }}</th>
                                    <th>{{ translate('الحالة') }}</th>
                                    <th>{{ translate('إجراءات') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($notifications as $key => $notification)
                                    <tr>
                                        <td>{{ $notifications->firstItem() + $key }}</td>
                                        <td><img src="{{ $notification->image_fullpath }}" alt="n"
                                                 style="width:44px;height:44px;object-fit:cover;border-radius:8px"></td>
                                        <td>
                                            {{ $notification->title }}<br>
                                            <small class="text-muted">{{ \Illuminate\Support\Str::limit($notification->description, 50) }}</small>
                                        </td>
                                        <td><span class="badge bg-soft-info text-info">{{ translate($notification->receiver ?? 'all') }}</span></td>
                                        <td>
                                            <button type="button"
                                               onclick="notifToggle('{{ route('admin.notification.status', ['id' => $notification->id, 'status' => $notification->status ? 0 : 1]) }}')"
                                               class="badge border-0 {{ $notification->status ? 'bg-soft-success text-success' : 'bg-soft-secondary text-secondary' }}">
                                                {{ $notification->status ? translate('مفعّل') : translate('معطّل') }}
                                            </button>
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.notification.edit', ['id' => $notification->id]) }}"
                                               class="btn btn-sm btn-outline-primary"><i class="tio-edit"></i></a>
                                            <form method="POST" action="{{ route('admin.notification.delete', ['id' => $notification->id]) }}" class="d-inline"
                                                  onsubmit="return confirm('{{ translate('حذف هذا الإشعار؟') }}')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger"><i class="tio-delete"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center py-5 text-muted">{{ translate('لا توجد إشعارات') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($notifications->hasPages())
                    <div class="card-footer border-0 d-flex justify-content-center">{!! $notifications->links() !!}</div>
                @endif
            </div>
        </div>
    </div>
</div>
<script>
    // AMIAL-CSRF-001: تفعيل إشعار وتعطيله تغيير في الحالة — POST مع رمز.
    function notifToggle(url) {
        fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } })
            .then(function () { location.reload(); });
    }
</script>
@endsection
