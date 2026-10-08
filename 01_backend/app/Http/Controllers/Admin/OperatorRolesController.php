<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PlatformLoginPinMail;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PlatformLoginPinService;
use App\Services\PlatformRoleService;
use App\Services\PlatformTabAccessService;
use App\Support\PlatformAccessTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AMIAL-OPERATOR-RBAC-003 + AMIAL-PLATFORM-LOGIN-PIN-001
 *
 * إدارة موظفي المنصّة وأدوارهم وPIN دخولهم. PIN الدخول ليس transaction_pin
 * ولا يُعرض بعد الإصدار، ولا يُكتب في التدقيق أو الـlogs.
 */
class OperatorRolesController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PlatformLoginPinService $loginPins,
        private readonly PlatformRoleService $platformRoles,
        private readonly PlatformTabAccessService $tabAccess,
    ) {
    }

    public function index()
    {
        $roles = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', 'like', 'platform_%')
            ->get(['id', 'code', 'label_ar']);

        $operators = User::where('type', 0)
            ->orderBy('id')
            ->get(['id', 'f_name', 'l_name', 'phone', 'email', 'is_active']);

        $pinStatuses = $this->loginPins->statusForUsers($operators->pluck('id')->all());

        $operators = $operators->map(function ($u) use ($pinStatuses) {
            $u->role_ids = DB::table('admin_user_roles')
                ->where('user_id', $u->id)->pluck('role_id')->all();
            $u->login_pin_status = $pinStatuses[$u->id] ?? null;
            $u->tab_access = $this->tabAccess->for($u);
            // **وما مُنح فعلاً** — ملخّصُ التبويبات لا يعرف أنّ الموظّف
            // مُنح صلاحيّةً واحدةً منه، فرسمُ الشاشة منه يُظهره مالكاً
            // للتبويب كلِّه، ويُوسّع المنحَ عند أوّل حفظ.
            $u->granted_permissions = $this->tabAccess->permissionsFor($u);

            return $u;
        });

        $viewer = request()->user();
        $canResetPins = $viewer
            && $this->platformRoles->has($viewer, PlatformRoleService::ADMIN);

        return view('admin-views.amial.ops.roles', [
            'roles' => $roles,
            'tabs' => PlatformAccessTabs::all(),
            'all_permissions' => PlatformAccessTabs::allPermissions(),
            'operators' => $operators,
            'can_reset_pins' => $canResetPins,
            'can_manage' => $viewer && $viewer->hasPlatformPermission('platform.staff.manage'),
            'current_user_id' => (int) ($viewer?->id ?? 0),
        ]);
    }

    /**
     * إنشاء موظف مع دوره وPIN عشوائي في معاملة واحدة، ثم إرسال PIN بالبريد.
     * البريد إلزامي لأن PIN لا يُعرض للمدير ولا يُخزن كنص صريح.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'f_name' => 'required|string|max:100',
            'l_name' => 'nullable|string|max:100',
            'phone' => 'required|string|min:6|max:20',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:8',
            // **أحدُهما يكفي**: تبويبٌ كامل، أو صلاحيّاتٌ مفرَّقة.
            // واشتراطُ التبويب يُبطل المنحَ الدقيقَ من بابه.
            'tab_access' => 'array',
            'permissions' => 'array',
            'permissions.*' => 'string|max:64',
        ], [
            'email.required' => 'البريد الإلكتروني إلزامي لإرسال PIN الموظف.',
            'email.unique' => 'البريد الإلكتروني مستخدم في حساب آخر.',
        ]);

        $phone = \App\CentralLogics\Helpers::filter_phone($data['phone']);
        $taken = User::whereIn('phone', \App\Support\Phone::variants($phone))->exists();
        if ($taken) {
            return back()->withInput()
                ->withErrors(['phone' => 'الرقم مستعمل في حساب آخر']);
        }

        $pin = $this->loginPins->generate();

        $operator = DB::transaction(function () use ($data, $phone, $request, $pin) {
            $u = new User();
            $u->f_name = $data['f_name'];
            $u->l_name = $data['l_name'] ?? '';
            $u->phone = $phone;
            $u->email = $data['email'];
            $u->password = \Illuminate\Support\Facades\Hash::make($data['password']);
            $u->type = 0;
            $u->is_active = 1;
            $u->is_phone_verified = 1;
            $u->save();

            // الموظف الجديد، حتى لو حمل دور platform_admin، يأخذ PIN عشوائياً.
            $this->loginPins->issue(
                $u,
                $pin,
                (int) $request->user()->id,
                'operator_created',
                mustChange: false,
                deliveryStatus: 'pending',
            );

            return $u;
        });

        try {
            $tabs = $this->tabAccess->sync(
                $operator,
                (array) ($data['tab_access'] ?? []),
                (int) $request->user()->id,
                (array) ($data['permissions'] ?? []),
            );
        } catch (\Throwable $e) {
            // لا يبقى حساب دخول بلا صلاحيات إذا فشل تحويل التبويبات.
            $operator->delete();
            return back()->withInput()->withErrors(['tab_access' => $e->getMessage()]);
        }

        $delivery = $this->sendPin($operator, $pin, 'issued');

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'user',
            'subject_id' => (string) $operator->id,
            'action' => 'ADMIN_OPERATOR_CREATED',
            'decision_code' => 'OPERATOR_CREATED',
            'reason' => 'إنشاء موظف منصة من شاشة الأدوار',
            'context' => [
                'tabs' => $tabs,
                'login_pin_issued' => true,
                'pin_delivery' => $delivery,
                // PIN نفسه ممنوع من سجل التدقيق.
            ],
            'severity' => 'warning',
        ]);

        if ($delivery !== 'sent') {
            return back()->with('error',
                'أُنشئ الموظف «' . $operator->f_name . '» لكن تعذر إرسال PIN إلى بريده. '
                . 'أصلح إعدادات البريد ثم استخدم «إعادة إصدار PIN»؛ لن يظهر الرمز في اللوحة.');
        }

        return back()->with('success',
            'أُنشئ الموظف «' . $operator->f_name . '» وأُرسل PIN عشوائي من 4 أرقام إلى ' . $operator->email);
    }

    public function update(Request $request, int $userId): RedirectResponse
    {
        $operator = User::where('type', 0)->find($userId);
        if (! $operator) {
            return back()->with('error', 'الحساب غير موجود أو ليس حساب إدارة');
        }

        $action = (string) $request->input('operator_action', 'roles');

        if ($action === 'reset_login_pin') {
            return $this->resetLoginPin($request, $operator);
        }

        if ($action === 'change_own_login_pin') {
            return $this->changeOwnLoginPin($request, $operator);
        }

        if ($action === 'tabs') {
            if ((int) $operator->id === (int) $request->user()->id) {
                return back()->with('error', 'لا تعدّل تبويبات حسابك من حسابك؛ يحتاج ذلك مدير منصة آخر.');
            }
            try {
                $tabs = $this->tabAccess->sync(
                    $operator,
                    (array) $request->input('tab_access', []),
                    (int) $request->user()->id,
                    (array) $request->input('permissions', []),
                );
            } catch (\Throwable $e) {
                return back()->withErrors(['tab_access' => $e->getMessage()]);
            }
            $this->audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $request->user()->id,
                'subject_type' => 'operator', 'subject_id' => (string) $operator->id,
                'action' => 'OPERATOR_TAB_ACCESS_CHANGED', 'decision_code' => 'TAB_ACCESS_CHANGED',
                'severity' => 'critical', 'context' => ['tabs' => $tabs],
            ]);
            return back()->with('success', 'حُدّثت تبويبات الموظف وصلاحيات القراءة/الكتابة.');
        }

        if ($action !== 'roles') {
            abort(422, 'إجراء موظف غير معروف');
        }

        $data = $request->validate([
            'role_ids' => 'present|array',
            'role_ids.*' => 'integer|exists:roles,id',
        ]);

        $before = DB::table('admin_user_roles')->where('user_id', $userId)
            ->pluck('role_id')->all();
        $after = $this->platformRoleIds($data['role_ids']);
        if (count($after) !== count(array_unique(array_map('intval', $data['role_ids'])))) {
            return back()->withErrors([
                'role_ids' => 'لا يجوز إسناد دور غير تابع لموظفي المنصّة',
            ]);
        }

        if ($operator->id === $request->user()->id
            && ! $this->keepsAdminRole($after)) {
            return back()->with('error',
                'لا يمكنك سحب دور مدير المنصّة عن نفسك — اطلب من مدير آخر فعلها');
        }

        if ($this->wouldLeaveNoAdmin($operator->id, $after)) {
            return back()->with('error',
                'لا يمكن ترك المنصّة بلا مدير — أسند الدور إلى حساب آخر أولاً');
        }

        if ($after === []) {
            return back()->withErrors([
                'role_ids' => 'لا يُترك حساب إدارة نشط بلا دور — عطّل الحساب إن لم يعد يعمل',
            ]);
        }

        DB::transaction(function () use ($userId, $after, $request) {
            DB::table('admin_user_roles')->where('user_id', $userId)->delete();
            foreach ($after as $roleId) {
                DB::table('admin_user_roles')->insert([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'granted_by_user_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'operator',
            'subject_id' => (string) $userId,
            'action' => 'roles_changed',
            'decision_code' => 'ROLES_CHANGED',
            'reason' => 'تعديل أدوار موظف من لوحة الإدارة',
            'severity' => 'critical',
            'context' => [
                'before' => $before,
                'after' => $after,
                'labels' => DB::table('roles')->whereIn('id', $after)
                    ->pluck('label_ar')->all(),
            ],
        ]);

        return back()->with('success', 'حُدِّثت أدوار الموظف');
    }

    /**
     * نسيان PIN: لا يملك إعادة الإصدار إلا من يحمل دور platform_admin فعلياً.
     * امتلاك settings.update وحده لا يكفي.
     */
    private function resetLoginPin(Request $request, User $operator): RedirectResponse
    {
        $actor = $request->user();
        if (! $actor || ! $this->platformRoles->has($actor, PlatformRoleService::ADMIN)) {
            abort(403, 'إعادة إصدار PIN متاحة لمدير المنصّة فقط');
        }

        if (! $operator->email) {
            return back()->with('error',
                'لا يمكن إعادة إصدار PIN قبل إضافة بريد إلكتروني صالح لهذا الموظف.');
        }

        $pin = $this->loginPins->generate();
        $this->loginPins->issue(
            $operator,
            $pin,
            (int) $actor->id,
            'admin_reset',
            mustChange: false,
            deliveryStatus: 'pending',
        );

        $delivery = $this->sendPin($operator, $pin, 'reset');

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'operator',
            'subject_id' => (string) $operator->id,
            'action' => 'PLATFORM_LOGIN_PIN_RESET',
            'decision_code' => $delivery === 'sent' ? 'PIN_RESET' : 'PIN_RESET_DELIVERY_FAILED',
            'reason' => 'إعادة إصدار PIN دخول موظف بواسطة مدير المنصّة',
            'severity' => 'warning',
            'context' => [
                'delivery' => $delivery,
                'email_configured' => true,
            ],
        ]);

        if ($delivery !== 'sent') {
            return back()->with('error',
                'تم تغيير PIN داخلياً لكن تعذر إرساله إلى بريد الموظف. '
                . 'أصلح البريد ثم أعد الإصدار مرة أخرى؛ لا تُرسل الرمز يدوياً من اللوحة.');
        }

        return back()->with('success',
            'أُصدر PIN جديد للموظف «' . $operator->f_name . '» وأُرسل إلى بريده. PIN السابق لم يعد صالحاً.');
    }

    /**
     * من يعرف PIN الحالي يستطيع تغييره لنفسه. من نسيه لا يوجد bypass:
     * يحتاج مدير المنصة إلى resetLoginPin ثم يصل الرمز الجديد بالبريد.
     */
    private function changeOwnLoginPin(Request $request, User $operator): RedirectResponse
    {
        $actor = $request->user();
        if (! $actor || (int) $actor->id !== (int) $operator->id) {
            abort(403, 'يمكنك تغيير PIN لحسابك فقط');
        }

        $data = $request->validate([
            'current_login_pin' => ['required', 'regex:/^\d{4}$/'],
            'new_login_pin' => ['required', 'regex:/^\d{4}$/', 'confirmed'],
        ], [
            'current_login_pin.required' => 'أدخل PIN الحالي.',
            'current_login_pin.regex' => 'PIN الحالي يجب أن يتكون من 4 أرقام.',
            'new_login_pin.required' => 'أدخل PIN الجديد.',
            'new_login_pin.regex' => 'PIN الجديد يجب أن يتكون من 4 أرقام.',
            'new_login_pin.confirmed' => 'تأكيد PIN الجديد غير مطابق.',
        ]);

        if ($data['current_login_pin'] === $data['new_login_pin']) {
            return back()->withErrors([
                'new_login_pin' => 'اختر PIN جديداً مختلفاً عن الرمز الحالي.',
            ]);
        }

        $verified = $this->loginPins->verify($operator, $data['current_login_pin']);
        if (! $verified['ok']) {
            return back()->withErrors([
                'current_login_pin' => $verified['reason'] === 'locked'
                    ? 'PIN مقفل مؤقتاً بعد محاولات غير صحيحة. انتظر ثم أعد المحاولة.'
                    : 'PIN الحالي غير صحيح.',
            ]);
        }

        $this->loginPins->issue(
            $operator,
            $data['new_login_pin'],
            (int) $actor->id,
            'self_change',
            mustChange: false,
            deliveryStatus: 'not_required',
        );

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'operator',
            'subject_id' => (string) $operator->id,
            'action' => 'PLATFORM_LOGIN_PIN_CHANGED',
            'decision_code' => 'PIN_CHANGED',
            'reason' => 'غيّر الموظف PIN دخول حسابه بعد التحقق من الرمز الحالي',
            'severity' => 'notice',
            'context' => ['self_service' => true],
        ]);

        return back()->with('success', 'تم تغيير PIN الخاص بحسابك.');
    }

    /** لا يعيد PIN مطلقاً؛ النتيجة تصف نجاح القناة فقط. */
    private function sendPin(User $operator, string $pin, string $reason): string
    {
        try {
            $name = trim(($operator->f_name ?? '') . ' ' . ($operator->l_name ?? ''));
            Mail::to((string) $operator->email)
                ->send(new PlatformLoginPinMail($name, $pin, $reason));

            $this->loginPins->markDelivered((int) $operator->id);

            return 'sent';
        } catch (\Throwable $e) {
            $this->loginPins->markDeliveryFailed((int) $operator->id);
            Log::warning('platform-login-pin: delivery failed', [
                'operator_id' => $operator->id,
                'error_class' => get_class($e),
                // لا PIN ولا بريد كامل ولا نص الاستثناء في السجل.
            ]);

            return 'failed';
        }
    }

    private function adminRoleId(): ?int
    {
        return DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
    }

    /** لا يقبل POST مزوراً دور تاجر أو دوراً محلياً باسم موظف منصة. */
    private function platformRoleIds(array $roleIds): array
    {
        $requested = array_values(array_unique(array_map('intval', $roleIds)));

        return DB::table('roles')->whereIn('id', $requested)
            ->whereNull('merchant_user_id')
            ->where('code', 'like', 'platform_%')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function keepsAdminRole(array $roleIds): bool
    {
        return in_array($this->adminRoleId(), $roleIds, true);
    }

    /** هل يبقى مدير واحد على الأقل بعد هذا التعديل؟ */
    private function wouldLeaveNoAdmin(int $userId, array $after): bool
    {
        $adminRoleId = $this->adminRoleId();
        if ($adminRoleId === null) {
            return false;
        }

        $others = DB::table('admin_user_roles')
            ->where('role_id', $adminRoleId)
            ->where('user_id', '!=', $userId)
            ->count();

        return $others === 0 && ! in_array($adminRoleId, $after, true);
    }
}
