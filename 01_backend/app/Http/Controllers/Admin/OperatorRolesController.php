<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPERATOR-RBAC-003 — إسناد أدوار فريق المنصّة.
 *
 * **الفجوة التي يسدّها:** الهجرة تُسند دور مدير المنصّة لحسابات الإدارة
 * القائمة، فلا تُقفل اللوحة على مالكها. أمّا الحساب الذي يُنشأ **بعدها**
 * فيولد بلا دور — والافتراض منعٌ لا سماح، فلا يستطيع شيئاً.
 *
 * وذلك صحيحٌ أمنياً وقاتلٌ عملياً بلا هذه الصفحة: يُضاف موظّف دعم فلا يفتح
 * شيئاً، ولا سبيل إلى منحه إلا بكتابة صفٍّ في قاعدة البيانات يدوياً.
 *
 * **وإسناد الصلاحية فعلٌ أخطر من استعمالها:** من يمنح دور مدير المنصّة يمنح
 * كل شيء دفعةً واحدة. فلا يملكه إلا مدير المنصّة نفسه، وكل منح وسحب يُكتب
 * في سجلّ التدقيق باسم فاعله.
 */
class OperatorRolesController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index()
    {
        $roles = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', 'like', 'platform_%')
            ->get(['id', 'code', 'label_ar']);

        $operators = User::where('type', 0)
            ->orderBy('id')
            ->get(['id', 'f_name', 'l_name', 'phone', 'email', 'is_active'])
            ->map(function ($u) {
                $u->role_ids = DB::table('admin_user_roles')
                    ->where('user_id', $u->id)->pluck('role_id')->all();
                return $u;
            });

        return view('admin-views.amial.ops.roles', [
            'roles' => $roles,
            'operators' => $operators,
        ]);
    }

    /**
     * **إنشاءُ موظّفِ منصّةٍ بأدواره في خطوةٍ واحدة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمن الذي دُفع:** فتح صاحبُ المشروع «أدوار الموظّفين» فوجد
     * جدولاً يُسند الأدوارَ **لحساباتٍ قائمةٍ فقط** — ولا سبيلَ إلى إنشاء
     * موظّفٍ من هنا. وقال: «يجب إنشاء موظّف مع اختيار صلاحياته ورقم
     * الهاتف وكلمة المرور».
     *
     * وكان البديلُ أن يُنشأ الحسابُ من «قائمة العملاء» بنوعِ إدارة — وهي
     * شاشةٌ لا تعرف الأدوارَ أصلاً — ثمّ يُبحث عنه هنا ليُسنَد. **خطوتان
     * في شاشتين، وبينهما حسابُ إدارةٍ حيٌّ بلا دور.**
     *
     * **والدورُ يُسند في المعاملة نفسِها**: حسابُ إدارةٍ يُنشأ ثمّ يسقط
     * إسنادُ دوره يبقى قائماً بلا صلاحيّة — بابٌ مفتوحٌ بلا حارس.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'f_name' => 'required|string|max:100',
            'l_name' => 'nullable|string|max:100',
            'phone' => 'required|string|min:6|max:20',
            'email' => 'nullable|email|max:190',
            // **ثمانيةٌ حدٌّ أدنى**: هذا حسابٌ يفتح لوحةَ الإدارة.
            'password' => 'required|string|min:8',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'integer|exists:roles,id',
        ]);

        $phone = \App\CentralLogics\Helpers::filter_phone($data['phone']);
        $roleIds = $this->platformRoleIds($data['role_ids']);
        if (count($roleIds) !== count(array_unique(array_map('intval', $data['role_ids'])))) {
            return back()->withInput()->withErrors(['role_ids' => 'لا يجوز إسناد دورٍ غير تابع لموظفي المنصّة']);
        }

        // **ولا يُنشأ حسابٌ برقمٍ مأخوذ** — وإلّا صار للرقم حسابان
        // ودخولٌ لا يُعرف أيَّهما يفتح.
        $taken = User::whereIn('phone', \App\Support\Phone::variants($phone))->exists();

        if ($taken) {
            // **الخطأُ يعود على الحقل نفسِه** لا في شريطٍ عامّ: `withErrors`
            // يُعيد المدخلاتِ ويضع الرسالةَ تحت خانة الهاتف، فيصحّح من أخطأ
            // رقماً واحداً بلا إعادة كتابة النموذج كلِّه.
            return back()->withInput()
                ->withErrors(['phone' => 'الرقم مستعملٌ في حسابٍ آخر']);
        }

        $operator = DB::transaction(function () use ($data, $phone, $roleIds, $request) {
            $u = new User();
            $u->f_name = $data['f_name'];
            $u->l_name = $data['l_name'] ?? '';
            $u->phone = $phone;
            $u->email = $data['email'] ?? null;
            $u->password = \Illuminate\Support\Facades\Hash::make($data['password']);
            $u->type = 0;              // حسابُ إدارة
            $u->is_active = 1;
            $u->is_phone_verified = 1; // أدخله مديرٌ بنفسه، ولا OTP في هذا المسار
            $u->save();

            foreach ($roleIds as $roleId) {
                DB::table('admin_user_roles')->insert([
                    'user_id' => $u->id,
                    'role_id' => $roleId,
                    'granted_by_user_id' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $u;
        });

        // **وإنشاءُ حسابِ إدارةٍ يُسجَّل** — هو أخطرُ ما يُنشأ في المنصّة.
        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'user',
            'subject_id' => (string) $operator->id,
            'action' => 'ADMIN_OPERATOR_CREATED',
            'decision_code' => 'OPERATOR_CREATED',
            'reason' => 'إنشاءُ موظّف منصّةٍ من شاشة الأدوار',
            'context' => ['roles' => $roleIds],
            'severity' => 'warning',
        ]);

        return back()->with('success',
            'أُنشئ الموظّف «' . $operator->f_name . '» — يدخل بالهاتف وكلمة المرور');
    }

    public function update(Request $request, int $userId): RedirectResponse
    {
        // AMIAL-OPERATOR-LASTADMIN-001 — **الحارسُ الذي كان يحرسه غيرُه.**
        //
        // كان الشرطُ `required|array|min:1`، فقائمةٌ فارغةٌ تُردّ **بخطأ
        // تحقّقٍ من الحقل** قبل أن يُسأل «أيترك هذا المنصّةَ بلا مدير؟».
        // فالنتيجةُ ردٌّ صحيحٌ لسببٍ خاطئ: يُردّ سحبُ الأدوار عن **أيّ**
        // موظّفٍ كما يُردّ عن آخر مدير — والحمايةُ الحقيقيّةُ لم تُشغَّل قطّ.
        // وحارسٌ لم يُشغَّل لا يُعرف أيعمل أم لا. (‏القاعدة الثانية.)
        //
        // فالإفراغُ صار **مقبولاً شكلاً** ليردّه من يملك ردَّه: `wouldLeaveNoAdmin`
        // و`keepsAdminRole` أدناه — وهما يقولان السببَ الحقيقيّ للموظّف.
        $data = $request->validate([
            'role_ids' => 'present|array',
            'role_ids.*' => 'integer|exists:roles,id',
        ]);

        $operator = User::where('type', 0)->find($userId);
        if (!$operator) {
            return back()->with('error', 'الحساب غير موجود أو ليس حساب إدارة');
        }

        $before = DB::table('admin_user_roles')->where('user_id', $userId)
            ->pluck('role_id')->all();
        $after = $this->platformRoleIds($data['role_ids']);
        if (count($after) !== count(array_unique(array_map('intval', $data['role_ids'])))) {
            return back()->withErrors(['role_ids' => 'لا يجوز إسناد دورٍ غير تابع لموظفي المنصّة']);
        }

        // من يسحب دوره عن نفسه يُقفل الباب وهو داخله، ولا أحد يفتحه له.
        if ($operator->id === $request->user()->id
            && !$this->keepsAdminRole($after)) {
            return back()->with('error',
                'لا يمكنك سحب دور مدير المنصّة عن نفسك — اطلب من مدير آخر فعلها');
        }

        // ولا يبقى النظام بلا مدير واحد على الأقلّ.
        if ($this->wouldLeaveNoAdmin($operator->id, $after)) {
            return back()->with('error',
                'لا يمكن ترك المنصّة بلا مدير — أسنِد الدور إلى حساب آخر أوّلاً');
        }

        // **وحسابُ إدارةٍ نشطٌ بلا دورٍ حالةٌ غامضة**: يدخل اللوحةَ ولا يرى
        // شيئاً، فيعود صاحبُه يسأل «لماذا لا تعمل؟». الصحيحُ تعطيلُ الحساب
        // لا تفريغُه. وموضعُ هذا الفحص **بعد** الحارسين أعلاه مقصود: آخرُ
        // مديرٍ يفرّغ دورَه يستحقّ الرسالةَ التي تخصّه، لا رسالةَ حقلٍ عامّة.
        if ($after === []) {
            return back()->withErrors(['role_ids' =>
                'لا يُترك حسابُ إدارةٍ نشطٌ بلا دور — عطّل الحساب إن لم يعد يعمل']);
        }

        DB::transaction(function () use ($userId, $after, $request) {
            DB::table('admin_user_roles')->where('user_id', $userId)->delete();
            foreach ($after as $roleId) {
                DB::table('admin_user_roles')->insert([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'granted_by_user_id' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
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
            'reason' => 'تعديل أدوار موظّف من لوحة الإدارة',
            'severity' => 'critical',
            'context' => [
                'before' => $before,
                'after' => $after,
                'labels' => DB::table('roles')->whereIn('id', $after)
                    ->pluck('label_ar')->all(),
            ],
        ]);

        return back()->with('success', 'حُدِّثت أدوار الموظّف');
    }

    private function adminRoleId(): ?int
    {
        return DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
    }

    /** لا يقبل POST مُزوّراً دور تاجرٍ أو دوراً محلياً باسم موظف منصة. */
    private function platformRoleIds(array $roleIds): array
    {
        $requested = array_values(array_unique(array_map('intval', $roleIds)));

        return DB::table('roles')->whereIn('id', $requested)
            ->whereNull('merchant_user_id')->where('code', 'like', 'platform_%')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function keepsAdminRole(array $roleIds): bool
    {
        return in_array($this->adminRoleId(), $roleIds, true);
    }

    /** هل يبقى مديرٌ واحد على الأقلّ بعد هذا التعديل؟ */
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

        return $others === 0 && !in_array($adminRoleId, $after, true);
    }
}
