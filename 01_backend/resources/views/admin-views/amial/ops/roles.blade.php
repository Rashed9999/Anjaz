@extends('layouts.admin.app')

@section('title', 'أدوار الموظفين')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-user-switch" style="font-size:24px"></i>
        <div>
            <h2 class="page-header-title mb-1">أدوار الموظفين</h2>
            <small class="text-muted">الحساب الوظيفي = هاتف + كلمة مرور + PIN من 4 أرقام + صلاحيّاتٌ مختارةٌ واحدةً واحدة.</small>
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
        الحساب بلا صلاحيّة لا يفتح شيئاً. تُمنَح الصلاحيّاتُ **مفرّقةً** — صلاحيّةً صلاحيّة — أو مجموعةً كاملةً بـ«الكلّ»؛ وPIN الدخول منفصل عن PIN المعاملات للعملاء.
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

    @if($can_manage)
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
                    <div class="col-12">
                        <label class="form-label small">تبويبات عمل الموظف *</label>
                        {{-- AMIAL-OPERATOR-GRAIN-002 — **الحبّةُ صلاحيّةٌ لا تبويب.**
                             كان التبويبُ يُمنَح كتلةً واحدة، فمن أراد مدقّقاً
                             يقرأ سجلَّ التدقيق وحدَه اضطُرّ أن يفتح له ملفّاتِ
                             العملاء والهويّاتِ معه. --}}
                        <div class="row g-2">
                            @php($oldPerms = (array) old('permissions', []))
                            @foreach ($tabs as $code => $tab)
                                <div class="col-md-6 col-xl-4"><div class="border rounded p-2 h-100">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <strong class="small">{{ $tab['icon'] }} {{ $tab['label'] }}</strong>
                                        <span class="text-nowrap">
                                            <label class="small me-2"><input type="checkbox" name="tab_access[{{ $code }}][read]" value="1" @checked(data_get(old('tab_access', []), $code.'.read'))> الكلّ قراءة</label>
                                            <label class="small"><input type="checkbox" name="tab_access[{{ $code }}][write]" value="1" @checked(data_get(old('tab_access', []), $code.'.write'))> الكلّ كتابة</label>
                                        </span>
                                    </div>
                                    @foreach (['read' => 'قراءة', 'write' => 'كتابة'] as $level => $levelLabel)
                                        @if (!empty($tab[$level]))
                                            <div class="small text-muted mt-1">{{ $levelLabel }}</div>
                                            @foreach ($tab[$level] as $perm => $permLabel)
                                                <label class="small d-block ps-2">
                                                    <input type="checkbox" name="permissions[]" value="{{ $perm }}"
                                                           @checked(in_array($perm, $oldPerms, true))>
                                                    {{ $permLabel }}
                                                </label>
                                            @endforeach
                                        @endif
                                    @endforeach
                                </div></div>
                            @endforeach
                        </div>
                        <div class="form-text">
                            أشِّر ما يحتاجه الموظّف بعينه — صلاحيّةً صلاحيّة. و«الكلّ قراءة/كتابة»
                            اختصارٌ يمنح المجموعة كاملةً، و«الكلّ كتابة» يفتح قراءتَها معها.
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary mt-3" type="submit" data-testid="operator-create-submit">
                    إنشاء الموظف وإرسال PIN
                </button>
            </form>
        </div>
    </div>
    @endif

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
                        <th>تبويبات العمل</th>
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
                        <td style="min-width:360px">
                            @if(empty($op->tab_access) && !empty($op->role_ids))
                                <div class="small text-warning mb-2">حساب قديم بالأدوار. اختر تبويباته ثم احفظ لتحويله للنظام الجديد.</div>
                            @endif
                            {{-- **وتُرسَم من الصلاحيّات الممنوحة فعلاً** لا من ملخّص
                                 التبويبات: من مُنح صلاحيّةً واحدةً كان يظهر مالكاً
                                 للتبويب كلِّه، فيتوسّع المنحُ عند أوّل حفظٍ بلا أن
                                 يطلب أحدٌ توسيعَه. --}}
                            @php($held = (array) ($op->granted_permissions ?? []))
                            <div class="row g-1">
                                @foreach ($tabs as $code => $tab)
                                    <div class="col-md-6"><div class="border rounded px-2 py-1 small">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <span>{{ $tab['icon'] }} {{ $tab['label'] }}</span>
                                            <span class="text-nowrap">
                                                <label class="me-1"><input type="checkbox" name="tab_access[{{ $code }}][read]" value="1" form="tabs-form-{{ $op->id }}" @disabled(!$can_manage)> الكلّ ق</label>
                                                <label><input type="checkbox" name="tab_access[{{ $code }}][write]" value="1" form="tabs-form-{{ $op->id }}" @disabled(!$can_manage)> الكلّ ك</label>
                                            </span>
                                        </div>
                                        @foreach (['read', 'write'] as $level)
                                            @foreach ($tab[$level] as $perm => $permLabel)
                                                <label class="d-block ps-2">
                                                    <input type="checkbox" name="permissions[]" value="{{ $perm }}"
                                                           form="tabs-form-{{ $op->id }}"
                                                           @checked(in_array($perm, $held, true)) @disabled(!$can_manage)>
                                                    <span class="@if($level === 'write') text-danger @endif">{{ $permLabel }}</span>
                                                </label>
                                            @endforeach
                                        @endforeach
                                    </div></div>
                                @endforeach
                            </div>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-2">
                                @if($can_manage)<form id="tabs-form-{{ $op->id }}" method="POST"
                                      action="{{ route('admin.amial.ops.roles.update', $op->id) }}">
                                    @csrf
                                    <input type="hidden" name="operator_action" value="tabs">
                                    <button class="btn btn-sm btn-primary" type="submit" @disabled((int)$op->id === $current_user_id)>حفظ التبويبات</button>
                                </form>@endif

                                {{-- ══════════════════════════════════════════════════════
                                     AMIAL-OPS-ROLES-NAV-001 — **دورٌ يُسنَد في الخادم ولا
                                     نموذجَ له في الشاشة.**

                                     نُقلت هذه الصفحةُ إلى «التبويبات»، فسقط نموذجُ إسناد
                                     الأدوار — **والمتحكّمُ ما زال يقبله**
                                     (`operator_action=roles`)، و`$roles` ما زالت تُمرَّر
                                     إلى القالب ولا تُعرَض.

                                     **والصلاحيّاتُ تُشتقّ من الأدوار لا من التبويبات**
                                     (`hasPlatformPermission` تقرأ `role_permissions`).
                                     فموظّفٌ جديدٌ بلا دورٍ لا يفتح شيئاً، **ولا سبيلَ
                                     لمنحه دوراً من أيّ شاشة**. ودورٌ لا يُسنَد ليس
                                     موجوداً عمليّاً — وهي القاعدة الثانية عشرة.
                                     ══════════════════════════════════════════════════════ --}}
                                @if($can_manage)
                                    <form method="POST" action="{{ route('admin.amial.ops.roles.update', $op->id) }}"
                                          class="d-flex flex-wrap align-items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="operator_action" value="roles">
                                        <select name="role_ids[]" multiple size="3"
                                                class="form-select form-select-sm" style="min-width:220px"
                                                @disabled((int)$op->id === $current_user_id)>
                                            @foreach ($roles as $role)
                                                <option value="{{ $role->id }}"
                                                    @selected(in_array($role->id, $op->platform_role_ids ?? [], true))>
                                                    {{ $role->label_ar ?? $role->code }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-sm btn-outline-primary" type="submit"
                                                @disabled((int)$op->id === $current_user_id)>حفظ الأدوار</button>
                                    </form>
                                @endif

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
        كل منح أو سحب تبويب، وإصدار أو تغيير PIN، يُكتب في سجل التدقيق باسم فاعله — من دون تسجيل PIN نفسه.
        إذا فشل البريد لا يظهر الرمز للمدير؛ أصلح قناة البريد ثم أعد الإصدار.
    </small>

</div>
@endsection
