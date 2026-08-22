@extends('layouts.admin.app')

@section('title', 'أدوار الموظفين')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-user-switch" style="font-size:24px"></i>
        <div>
            <h2 class="page-header-title mb-1">أدوار الموظفين</h2>
            <small class="text-muted">الحساب الوظيفي = هاتف + كلمة مرور + PIN من 4 أرقام + الدور والصلاحيات.</small>
        </div>
    </div>

    @foreach (['success' => 'success', 'error' => 'danger'] as $key => $tone)
        @if (session($key))
            <div class="alert alert-{{ $tone }}">{{ session($key) }}</div>
        @endif
    @endforeach

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>تعذر حفظ الطلب:</strong>
            <ul class="mb-0 mt-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="alert alert-soft-info">
        <strong>الافتراض منع لا سماح.</strong>
        الحساب بلا دور لا يفتح شيئاً. وPIN الدخول منفصل عن PIN المعاملات للعملاء.
        PIN الموظف لا يظهر في هذه الشاشة ولا يُحفظ كنص صريح؛ عند الإصدار أو إعادة التعيين يُرسل إلى بريد الموظف.
    </div>

    {{-- PIN مدير المنصة الحالي: تغيير ذاتي يتطلب معرفة الرمز الحالي. --}}
    @if($can_reset_pins && $current_user_id > 0)
        <div class="card mb-4" data-testid="own-login-pin-card">
            <div class="card-header d-flex align-items-center gap-2">
                <h5 class="card-header-title mb-0">🔐 PIN دخولي إلى الإدارة</h5>
                <span class="badge badge-soft-secondary ms-auto">4 أرقام</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    حساب مدير المنصة الجذري يبدأ بالرمز الافتراضي المحدد عند التهيئة. غيّره من هنا بعد أول دخول.
                    لا يمكن تغيير PIN بدون معرفة الرمز الحالي؛ عند النسيان يجب أن يعيد مدير منصة آخر إصداره.
                </p>
                <form method="POST" action="{{ route('admin.amial.ops.roles.update', $current_user_id) }}"
                      class="row g-3 align-items-end" data-testid="own-pin-change-form">
                    @csrf
                    <input type="hidden" name="operator_action" value="change_own_login_pin">
                    <div class="col-md-3">
                        <label class="form-label small">PIN الحالي</label>
                        <input type="password" name="current_login_pin" class="form-control"
                               inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4"
                               autocomplete="off" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">PIN الجديد</label>
                        <input type="password" name="new_login_pin" class="form-control"
                               inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4"
                               autocomplete="new-password" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">تأكيد PIN الجديد</label>
                        <input type="password" name="new_login_pin_confirmation" class="form-control"
                               inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4"
                               autocomplete="new-password" required>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-primary w-100" type="submit">تغيير PIN الخاص بي</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-header-title mb-0">➕ موظف جديد</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.amial.ops.operators.store') }}"
                  data-testid="operator-create-form">
                @csrf
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">الاسم الأول *</label>
                        <input name="f_name" class="form-control" value="{{ old('f_name') }}" required maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">اسم العائلة</label>
                        <input name="l_name" class="form-control" value="{{ old('l_name') }}" maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">رقم الهاتف *</label>
                        <input name="phone" class="form-control" value="{{ old('phone') }}" required dir="ltr"
                               placeholder="967xxxxxxxxx">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">البريد الإلكتروني *</label>
                        <input name="email" type="email" class="form-control" value="{{ old('email') }}" required dir="ltr">
                        <div class="form-text">يُرسل إليه PIN الدخول العشوائي؛ لذلك البريد إلزامي.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">كلمة المرور *</label>
                        <input name="password" type="password" class="form-control"
                               required minlength="8" autocomplete="new-password">
                        <div class="form-text">ثمانية محارف فأكثر — ولا تُرسل مع PIN في البريد.</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">الأدوار</label>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach ($roles as $role)
                                <label class="d-flex align-items-center gap-1 small">
                                    <input type="checkbox" name="role_ids[]" value="{{ $role->id }}"
                                           @checked(in_array((string)$role->id, array_map('strval', old('role_ids', [])), true))>
                                    {{ $role->label_ar ?? $role->code }}
                                </label>
                            @endforeach
                        </div>
                        <div class="form-text">بلا دور لا يفتح شيئاً — والافتراض منع لا سماح.</div>
                    </div>
                </div>
                <button class="btn btn-primary mt-3" type="submit" data-testid="operator-create-submit">
                    إنشاء الموظف وإرسال PIN
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title mb-0">موظفو المنصة</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>الموظف</th>
                        <th>الهاتف</th>
                        <th>حالة PIN</th>
                        <th>الأدوار</th>
                        <th style="min-width:190px">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($operators as $op)
                    @php($pin = $op->login_pin_status)
                    <tr>
                        <td>
                            <strong>{{ trim(($op->f_name ?? '') . ' ' . ($op->l_name ?? '')) ?: '—' }}</strong>
                            <br><small class="text-muted" dir="ltr">{{ $op->email ?: 'بلا بريد' }}</small>
                            @unless ($op->is_active)
                                <span class="badge badge-soft-secondary">معطل</span>
                            @endunless
                            @if((int)$op->id === $current_user_id)
                                <span class="badge badge-soft-primary">حسابي</span>
                            @endif
                        </td>
                        <td><code dir="ltr">{{ $op->phone }}</code></td>
                        <td>
                            @if(!$pin)
                                <span class="badge badge-soft-danger">لم يُصدر PIN</span>
                            @elseif($pin->delivery_status === 'failed')
                                <span class="badge badge-soft-danger">فشل إرسال PIN</span>
                            @elseif($pin->locked_until && now()->lt(\Illuminate\Support\Carbon::parse($pin->locked_until)))
                                <span class="badge badge-soft-danger">PIN مقفل مؤقتاً</span>
                            @elseif($pin->must_change)
                                <span class="badge badge-soft-warning">PIN أولي — غيّره</span>
                            @elseif($pin->delivery_status === 'pending')
                                <span class="badge badge-soft-warning">بانتظار الإرسال</span>
                            @else
                                <span class="badge badge-soft-success">PIN مفعّل</span>
                            @endif
                            @if($pin && (int)$pin->failed_attempts > 0)
                                <small class="text-muted d-block mt-1">محاولات فاشلة: {{ (int)$pin->failed_attempts }}</small>
                            @endif
                        </td>
                        <td>
                            @foreach ($roles as $role)
                                <label class="me-3 d-inline-block small">
                                    <input type="checkbox" name="role_ids[]" value="{{ $role->id }}"
                                           form="roles-form-{{ $op->id }}"
                                           @checked(in_array($role->id, $op->role_ids))>
                                    {{ $role->label_ar }}
                                </label>
                            @endforeach
                            @if (empty($op->role_ids))
                                <span class="badge badge-soft-warning">بلا دور — لا يفتح شيئاً</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-2">
                                <form id="roles-form-{{ $op->id }}" method="POST"
                                      action="{{ route('admin.amial.ops.roles.update', $op->id) }}">
                                    @csrf
                                    <input type="hidden" name="operator_action" value="roles">
                                    <button class="btn btn-sm btn-primary" type="submit">حفظ الأدوار</button>
                                </form>

                                @if($can_reset_pins && (int)$op->id !== $current_user_id)
                                    <form method="POST" action="{{ route('admin.amial.ops.roles.update', $op->id) }}"
                                          onsubmit="return confirm('سيُلغى PIN الحالي ويُرسل PIN جديد إلى بريد الموظف. متابعة؟')">
                                        @csrf
                                        <input type="hidden" name="operator_action" value="reset_login_pin">
                                        <button class="btn btn-sm btn-outline-warning" type="submit"
                                                @disabled(!$op->email)>
                                            إعادة إصدار PIN
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <small class="text-muted d-block mt-3">
        كل منح أو سحب دور، وإصدار أو تغيير PIN، يُكتب في سجل التدقيق باسم فاعله — من دون تسجيل PIN نفسه.
        إذا فشل البريد لا يظهر الرمز للمدير؛ أصلح قناة البريد ثم أعد الإصدار.
    </small>

</div>
@endsection
