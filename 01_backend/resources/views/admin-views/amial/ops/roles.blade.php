@extends('layouts.admin.app')

@section('title', 'أدوار الموظّفين')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-user-switch" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">أدوار الموظّفين</h2>
    </div>

    @foreach (['success' => 'success', 'error' => 'danger'] as $key => $tone)
        @if (session($key))
            <div class="alert alert-{{ $tone }}">{{ session($key) }}</div>
        @endif
    @endforeach

    <div class="alert alert-soft-info">
        <strong>الافتراض منعٌ لا سماح.</strong>
        الحساب بلا دور لا يفتح شيئاً. وامنح أقلّ ما يكفي الموظّف لعمله —
        فكل صلاحية زائدة خطرٌ بلا مقابل، ودورُ مدير المنصّة يمنح كل شيء دفعةً واحدة.
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-borderless table-align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>الموظّف</th>
                        <th>الهاتف</th>
                        <th>الأدوار</th>
                        <th style="width:120px">حفظ</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($operators as $op)
                    <tr>
                        <form method="POST" action="{{ route('admin.amial.ops.roles.update', $op->id) }}">
                            @csrf
                            <td>
                                <strong>{{ trim(($op->f_name ?? '') . ' ' . ($op->l_name ?? '')) ?: '—' }}</strong>
                                <br><small class="text-muted">{{ $op->email }}</small>
                                @unless ($op->is_active)
                                    <span class="badge badge-soft-secondary">معطّل</span>
                                @endunless
                            </td>
                            <td><code dir="ltr">{{ $op->phone }}</code></td>
                            <td>
                                @foreach ($roles as $role)
                                    <label class="me-3 d-inline-block">
                                        <input type="checkbox" name="role_ids[]" value="{{ $role->id }}"
                                               @checked(in_array($role->id, $op->role_ids))>
                                        {{ $role->label_ar }}
                                    </label>
                                @endforeach
                                @if (empty($op->role_ids))
                                    <span class="badge badge-soft-warning">بلا دور — لا يفتح شيئاً</span>
                                @endif
                            </td>
                            <td>
                                <button class="btn btn-sm btn-primary">حفظ</button>
                            </td>
                        </form>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <small class="text-muted d-block mt-3">
        كل منح وسحب يُكتب في سجلّ التدقيق باسم فاعله — إسناد الصلاحية فعلٌ
        أخطر من استعمالها.
    </small>

</div>
@endsection
