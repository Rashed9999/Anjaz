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

    {{-- ══════════════════════════════════════════════════════════════
         AMIAL-OPERATOR-CREATE-001 — **إنشاءُ موظّفٍ بأدواره في خطوةٍ واحدة.**

         كانت الشاشةُ تُسند الأدوارَ **لحساباتٍ قائمةٍ فقط**، فمن أراد
         موظّفاً جديداً أنشأه من «قائمة العملاء» بنوعِ إدارة — وهي شاشةٌ
         لا تعرف الأدوارَ — ثمّ عاد يبحث عنه هنا ليُسنِد.

         **خطوتان في شاشتين، وبينهما حسابُ إدارةٍ حيٌّ بلا دور.**
         ══════════════════════════════════════════════════════════════ --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-header-title mb-0">➕ موظّف جديد</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.amial.ops.operators.store') }}"
                  data-testid="operator-create-form">
                @csrf
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">الاسم الأوّل *</label>
                        <input name="f_name" class="form-control" required maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">اسم العائلة</label>
                        <input name="l_name" class="form-control" maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">رقم الهاتف *</label>
                        <input name="phone" class="form-control" required dir="ltr"
                               placeholder="967xxxxxxxxx">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">البريد (اختياريّ)</label>
                        <input name="email" type="email" class="form-control" dir="ltr">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">كلمة المرور *</label>
                        <input name="password" type="password" class="form-control"
                               required minlength="8" autocomplete="new-password">
                        <div class="form-text">ثمانية محارف فأكثر — هذا حسابٌ يفتح لوحة الإدارة.</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">الأدوار</label>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach ($roles as $role)
                                <label class="d-flex align-items-center gap-1 small">
                                    <input type="checkbox" name="role_ids[]" value="{{ $role->id }}">
                                    {{ $role->label_ar ?? $role->code }}
                                </label>
                            @endforeach
                        </div>
                        <div class="form-text">
                            **بلا دورٍ لا يفتح شيئاً** — والافتراضُ منعٌ لا سماح.
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary mt-3" type="submit" data-testid="operator-create-submit">
                    إنشاء الموظّف
                </button>
            </form>
        </div>
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
