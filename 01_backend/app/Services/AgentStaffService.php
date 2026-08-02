<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AMIAL-AGENT-STAFF-001 — موظّفو شركة الصرافة.
 *
 * الشركة تُدير موظّفيها بنفسها: المنصّة لا تعرف من عيّنت العمقي أمس ولا من
 * فصلت اليوم، ولا يجوز أن يتوقّف تعيينُ صرّافٍ على تذكرةِ دعمٍ عندنا.
 */
class AgentStaffService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * تعيين موظّف.
     *
     * ورمزُ الدخول يُولَّد من رمز الفرع وتسلسلٍ داخله — `MKL-014`. وذلك
     * لأنّ الموظّف يحفظه ويكتبه كلّ صباح: بريدٌ إلكترونيّ لا يملكه أكثرُ
     * صرّافي اليمن، وهاتفٌ يتغيّر ويُعاد استعماله بين الموظّفين فيرث الجديدُ
     * دخولَ القديم.
     */
    public function hire(AgentStaff $actor, array $data): AgentStaff
    {
        $this->assertCanManageStaff($actor);

        $role = (string) ($data['role'] ?? AgentStaff::ROLE_TELLER);
        if (!array_key_exists($role, AgentStaff::ROLE_LABELS)) {
            throw new DomainException('دور غير معروف');
        }

        // مدير الفرع يعيّن صرّافين في فرعه — لا مديري فروعٍ ولا إدارةً عامّة.
        // ولولا هذا لرقّى مديرُ فرعٍ نفسه بإنشاء حسابٍ أعلى منه.
        if (!$actor->isHeadOffice() && $role !== AgentStaff::ROLE_TELLER) {
            throw new DomainException('مدير الفرع يعيّن صرّافين فقط');
        }

        $branchId = $actor->isHeadOffice()
            ? ($data['branch_id'] ?? null)
            : $actor->branch_id;

        if ($role !== AgentStaff::ROLE_HEAD_OFFICE && empty($branchId)) {
            throw new DomainException('اختر الفرع الذي يعمل فيه الموظّف');
        }

        $branch = null;
        if ($branchId) {
            $branch = AgentBranch::where('id', $branchId)
                ->where('agent_user_id', $actor->agent_user_id)->first();

            if (!$branch) {
                throw new DomainException('الفرع غير تابعٍ لشركتك');
            }
        }

        $name = trim((string) ($data['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw new DomainException('اسم الموظّف إلزاميّ');
        }

        $password = (string) ($data['password'] ?? '');
        if (mb_strlen($password) < 6) {
            throw new DomainException('كلمة السرّ ستّة أحرف على الأقلّ');
        }

        return DB::transaction(function () use ($actor, $data, $role, $branch, $name, $password) {
            $username = $this->nextUsername($actor->agent_user_id, $branch);

            $staff = AgentStaff::create([
                'agent_user_id' => $actor->agent_user_id,
                'branch_id' => $branch?->id,
                'username' => $username,
                'name' => $name,
                'phone' => preg_replace('/\D+/', '', (string) ($data['phone'] ?? '')) ?: null,
                'password' => Hash::make($password),
                'role' => $role,
                'is_active' => true,
                'max_txn_amount' => (string) ($data['max_txn_amount'] ?? '0'),
                'created_by' => $actor->id,
            ]);

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $actor->agent_user_id,
                'action' => 'agent.staff.hire',
                'subject_type' => 'agent_staff',
                'subject_id' => $staff->id,
                'metadata' => [
                    'username' => $username, 'role' => $role,
                    'branch_id' => $branch?->id, 'by_staff_id' => $actor->id,
                ],
            ]);

            return $staff;
        });
    }

    /**
     * رمزُ دخولٍ فريدٌ داخل الشركة.
     *
     * يُقفل الجدول أثناء التوليد: شركةٌ فيها آلاف الفروع تُعيّن موظّفَين في
     * لحظةٍ واحدة، وقراءةُ آخر رقمٍ ثمّ زيادتُه بلا قفلٍ تُعطيهما الرمز نفسه
     * فيفشل أحدهما على قيد التفرّد — أو أسوأ، لو لم يكن القيد موجوداً،
     * ينجحان ويدخل أحدهما بحساب الآخر.
     */
    private function nextUsername(int $agentUserId, ?AgentBranch $branch): string
    {
        $prefix = $branch ? strtoupper($branch->code) : 'HQ';

        $last = AgentStaff::where('agent_user_id', $agentUserId)
            ->where('username', 'like', $prefix . '-%')
            ->lockForUpdate()
            ->orderByDesc('id')->value('username');

        $n = $last ? ((int) substr((string) $last, strlen($prefix) + 1)) + 1 : 1;

        // لو صادف الرمز واحداً قائماً (شركةٌ أخرى بنفس رمز الفرع) يُزاد حتى
        // يصير فريداً — والفريد على مستوى الجدول لا الشركة، لأنّ الدخول
        // يتمّ بالرمز وحده.
        do {
            $username = $prefix . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $n++;
        } while (AgentStaff::where('username', $username)->exists());

        return $username;
    }

    /** الموظّفون الذين يراهم هذا الفاعل — من دوره لا من طلبه. */
    public function listFor(AgentStaff $actor): array
    {
        $q = AgentStaff::where('agent_user_id', $actor->agent_user_id)->with('branch');

        if (!$actor->isHeadOffice()) {
            $q->where('branch_id', $actor->branch_id);
        }

        $openShifts = AgentShift::where('status', AgentShift::STATUS_OPEN)
            ->pluck('id', 'staff_id');

        // حالةُ واتساب تُقرأ مع الصفّ لا بطلبٍ لكلّ موظّف: شركةٌ فيها مئة
        // موظّف كانت ستُرسل مئة طلبٍ لتعرف من رقمُه مربوط.
        $waLinks = \App\Models\WhatsappLinkedDevice::whereNotNull('agent_staff_id')
            ->where('status', '!=', \App\Models\WhatsappLinkedDevice::STATUS_REVOKED)
            ->get(['agent_staff_id', 'whatsapp_number', 'status', 'alerts_enabled'])
            ->keyBy('agent_staff_id');

        return $q->orderBy('branch_id')->orderBy('username')->limit(500)->get()
            ->map(fn (AgentStaff $s) => [
                'id' => (int) $s->id,
                'username' => $s->username,
                'name' => $s->name,
                'phone' => $s->phone,
                'role' => $s->role,
                'role_label' => $s->roleLabel(),
                'branch' => $s->branch?->name,
                'branch_id' => $s->branch_id ? (int) $s->branch_id : null,
                'is_active' => (bool) $s->is_active,
                'max_txn_amount' => (string) $s->max_txn_amount,
                'last_login_at' => $s->last_login_at?->toDateTimeString(),
                // الورديّة المفتوحة تُعرَض مع الموظّف: موظّفٌ يُعطَّل وورديّته
                // مفتوحة يترك درجاً بلا جرد.
                'has_open_shift' => $openShifts->has($s->id),
                // «غير مربوط» و«بانتظار الرمز» حالتان مختلفتان: الأولى
                // تحتاج بدايةً، والثانية تحتاج أن يفتح الموظّف هاتفه.
                'whatsapp' => ($w = $waLinks->get($s->id)) ? [
                    'number' => (string) $w->whatsapp_number,
                    'status' => (string) $w->status,
                    'alerts_enabled' => (bool) $w->alerts_enabled,
                ] : null,
            ])->all();
    }

    /** تعطيل موظّف أو إعادته. */
    public function setActive(AgentStaff $actor, int $staffId, bool $active): AgentStaff
    {
        $this->assertCanManageStaff($actor);
        $staff = $this->findInScope($actor, $staffId);

        if ($staff->id === $actor->id) {
            throw new DomainException('لا تُعطّل حسابك — لن تستطيع الدخول لإعادته');
        }

        if (!$active && $staff->openShift()) {
            // موظّفٌ يُعطَّل وورديّتُه مفتوحة يترك نقداً في درجٍ لا أحد
            // مسؤولٌ عنه، ولا يستطيع هو إغلاقه بعد التعطيل.
            throw new DomainException('أغلق ورديّة الموظّف أوّلاً — درجُه ما زال مفتوحاً');
        }

        $staff->is_active = $active;
        $staff->save();

        $this->audit->record([
            'actor_type' => 'agent',
            'actor_user_id' => $actor->agent_user_id,
            'action' => $active ? 'agent.staff.enable' : 'agent.staff.disable',
            'subject_type' => 'agent_staff',
            'subject_id' => $staff->id,
            'metadata' => ['by_staff_id' => $actor->id],
        ]);

        return $staff;
    }

    /** إعادة تعيين كلمة سرّ موظّف. */
    public function resetPassword(AgentStaff $actor, int $staffId, string $password): AgentStaff
    {
        $this->assertCanManageStaff($actor);

        if (mb_strlen($password) < 6) {
            throw new DomainException('كلمة السرّ ستّة أحرف على الأقلّ');
        }

        $staff = $this->findInScope($actor, $staffId);
        $staff->password = Hash::make($password);
        $staff->save();

        $this->audit->record([
            'actor_type' => 'agent',
            'actor_user_id' => $actor->agent_user_id,
            'action' => 'agent.staff.reset_password',
            'subject_type' => 'agent_staff',
            'subject_id' => $staff->id,
            'metadata' => ['by_staff_id' => $actor->id],
        ]);

        return $staff;
    }

    /**
     * حساب الإدارة العامّة الأوّل لشركةٍ — يُشتقّ من حساب الوكيل نفسه.
     *
     * بدونه تبقى الشركة بلا مدخلٍ إلى بوّابتها: لا موظّف يُعيّن الموظّف
     * الأوّل. (وهذا نوعُ العطل الذي يمرّ في كلّ الاختبارات ويسقط أوّل يومٍ
     * حقيقيّ — كما سقط تمويل الفرع من قبل.)
     */
    public function ensureHeadOfficeAccount(User $agent, ?string $password = null): AgentStaff
    {
        $existing = AgentStaff::where('agent_user_id', $agent->id)
            ->where('role', AgentStaff::ROLE_HEAD_OFFICE)->first();

        if ($existing) {
            return $existing;
        }

        $base = 'HQ' . $agent->id;
        $username = $base;
        $i = 1;
        while (AgentStaff::where('username', $username)->exists()) {
            $username = $base . '-' . (++$i);
        }

        return AgentStaff::create([
            'agent_user_id' => $agent->id,
            'branch_id' => null,
            'username' => $username,
            'name' => trim(($agent->f_name ?? '') . ' ' . ($agent->l_name ?? '')) ?: 'الإدارة العامّة',
            'phone' => $agent->phone,
            // بلا كلمة سرٍّ صريحة يُنشأ الحساب بكلمةٍ عشوائيّة لا يعرفها أحد،
            // فيلزم ضبطُها من لوحة الإدارة قبل أوّل دخول — وذلك أسلم من
            // كلمةٍ افتراضيّةٍ معروفة تُنسى فتبقى.
            'password' => Hash::make($password ?: bin2hex(random_bytes(16))),
            'role' => AgentStaff::ROLE_HEAD_OFFICE,
            'is_active' => true,
        ]);
    }


    /**
     * حسابُ بوّابةٍ لمستخدمٍ من نوع وكيل — شركةً كان أو فرعاً.
     *
     * **ولماذا لا يكفي `ensureHeadOfficeAccount`.** حسابُ الفرع مستخدمٌ من
     * نوع وكيلٍ أيضاً. ولو عومل معاملة الشركة لصار «إدارةً عامّة» لنفسه —
     * فيرى فروع الشركة كلّها من حسابِ فرعٍ واحد. أي أنّ تبسيطَ الدخول كان
     * سيفتح تسرّب بياناتٍ بين الفروع.
     *
     * فيُميَّز بالصعود: حسابٌ له `parent_agent_id` فرعٌ، ويُفتح له حساب
     * **مدير فرع** محصورٌ بفرعه.
     */
    public function ensurePortalAccount(User $user): AgentStaff
    {
        // **لا يُفتح حساب بوّابةٍ لغير وكيل.** الدالّة تُنشئ حساباً بصلاحيات،
        // فلو قُبل فيها عميلٌ أو تاجرٌ لصار له مدخلٌ إلى بوّابة الوكلاء بحسابٍ
        // أنشأناه له نحن. والفحص هنا لا في المُنادي: مُنادٍ واحدٌ ينساه يفتح
        // الباب كلّه.
        if ((int) $user->type !== AGENT_TYPE) {
            throw new DomainException('هذه البوّابة للوكلاء وموظّفيهم');
        }

        $branch = AgentBranch::where('branch_user_id', $user->id)->first();

        if (!$branch) {
            return $this->ensureHeadOfficeAccount($user);
        }

        $existing = AgentStaff::where('agent_user_id', $branch->agent_user_id)
            ->where('branch_id', $branch->id)
            ->where('role', AgentStaff::ROLE_BRANCH_MANAGER)
            ->whereNull('phone')          // الحساب المشتقّ من حساب الفرع نفسه
            ->first();

        if ($existing) {
            return $existing;
        }

        $base = strtoupper($branch->code) . '-MGR';
        $username = $base;
        $i = 1;
        while (AgentStaff::where('username', $username)->exists()) {
            $username = $base . '-' . (++$i);
        }

        return AgentStaff::create([
            'agent_user_id' => $branch->agent_user_id,
            'branch_id' => $branch->id,
            'username' => $username,
            'name' => (string) $branch->name,
            'phone' => null,
            'password' => Hash::make(bin2hex(random_bytes(16))),
            'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'is_active' => true,
        ]);
    }

    private function assertCanManageStaff(AgentStaff $actor): void
    {
        if ($actor->isTeller()) {
            throw new DomainException('الصرّاف لا يُدير الموظّفين');
        }
    }

    private function findInScope(AgentStaff $actor, int $staffId): AgentStaff
    {
        $q = AgentStaff::where('id', $staffId)
            ->where('agent_user_id', $actor->agent_user_id);

        if (!$actor->isHeadOffice()) {
            $q->where('branch_id', $actor->branch_id);
        }

        $staff = $q->first();

        if (!$staff) {
            throw new DomainException('الموظّف غير موجود ضمن نطاقك');
        }

        return $staff;
    }
}
