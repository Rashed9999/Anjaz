<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPERATOR-RBAC-002 — إسنادُ دورِ المنصّة في موضعٍ واحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الذي أدخل هذا:**
 *
 * نظامُ الأدوار كان مبنيّاً كاملاً — أربعة أدوار، وثلاث عشرة صلاحية،
 * وسبعٌ وعشرون رابطة، ووسيطٌ يفحص، وواحدٌ وأربعون مساراً يستعمله. وكلُّه
 * يعمل.
 *
 * **ولا سطرَ واحدٍ في المشروع كلِّه يُسند دوراً لأدمنٍ جديد.**
 *
 * الإسنادُ الوحيد كان داخل الهجرة: تُسند `platform_admin` لكلّ حساب إدارة
 * **قائمٍ لحظةَ تشغيلها**. وهي تُشغَّل مرّةً. فكلّ حساب إدارةٍ يُنشأ بعدها
 * يولد بلا صلاحيةٍ واحدة، ويرى ٤٠٣ على واحدٍ وأربعين مساراً — بلا رسالةٍ
 * تقول لماذا، وبلا سطرٍ في أيّ سجلّ يدلّ على السبب.
 *
 * `EnsureDemoStaff` يُنشئ حساب الإدارة في كلّ إقلاع. فالعطل ليس نظريّاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا لا يُجعل «أدمنٌ بلا أدوار = كلّ الصلاحيات»؟**
 *
 * لأنّه يقلب الحارس إلى بابٍ مفتوح: من أراد تجاوزه نزع أدواره كلَّها فصار
 * يملك كلّ شيء. والغيابُ يُصلَح بالإسناد لا بتفسيره كرخصة.
 */
class PlatformRoleService
{
    public const ADMIN       = 'platform_admin';
    public const SUPERVISOR  = 'platform_supervisor';
    public const SUPPORT     = 'platform_support';
    public const MAINTENANCE = 'platform_maintenance';

    // AMIAL-OPERATOR-RBAC-003 — الأدوار الوظيفيّة الثلاثة.
    //
    // «لا يجوز أن يستطيع كلّ موظّف في أميال رؤية كلّ شيء»: فالمالُ لفريق
    // المالية، والإشاراتُ والتجميدُ لفريق المخاطر، والتوثيقُ لفريق الامتثال.
    // وربطُ كلٍّ بصلاحيّاته في هجرة `2026_08_10_160000`.
    public const FINANCE    = 'platform_finance';
    public const RISK       = 'platform_risk';
    public const COMPLIANCE = 'platform_compliance';

    /** رموز أدوار المنصّة كلّها — أدوار التجّار لها `merchant_user_id`. */
    public const ALL = [
        self::ADMIN, self::SUPERVISOR, self::SUPPORT, self::MAINTENANCE,
        self::FINANCE, self::RISK, self::COMPLIANCE,
    ];

    /** معرّف دورٍ برمزه، أو `null` إن لم تُشغَّل هجرة الأدوار بعد. */
    public function roleId(string $code): ?int
    {
        return DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', $code)
            ->value('id');
    }

    /** رموز الأدوار المسندة إلى حساب. */
    public function codesOf(User $user): array
    {
        return DB::table('admin_user_roles')
            ->join('roles', 'roles.id', '=', 'admin_user_roles.role_id')
            ->where('admin_user_roles.user_id', $user->id)
            ->pluck('roles.code')
            ->all();
    }

    public function has(User $user, string $code): bool
    {
        return in_array($code, $this->codesOf($user), true);
    }

    /**
     * يُسند دوراً. لا يُكرّر، ولا يمسّ ما سواه.
     *
     * @return bool أوقع إسنادٌ جديد؟
     */
    public function assign(User $user, string $code, ?int $byUserId = null): bool
    {
        $roleId = $this->roleId($code);

        if ($roleId === null) {
            return false;
        }

        $exists = DB::table('admin_user_roles')
            ->where('user_id', $user->id)->where('role_id', $roleId)->exists();

        if ($exists) {
            return false;
        }

        DB::table('admin_user_roles')->insert([
            'user_id'             => $user->id,
            'role_id'             => $roleId,
            'granted_by_user_id'  => $byUserId,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return true;
    }

    /**
     * **يضمن أنّ حساب الإدارة لا يولد بلا دور.**
     *
     * يُنادى من كلّ موضعٍ يُنشئ حساب إدارة. ولا يفعل شيئاً لمن له دورٌ
     * أصلاً — فلا يُعيد صلاحيةً سُحبت عمداً.
     */
    public function ensureHasSomeRole(User $user, string $default = self::ADMIN): bool
    {
        if ((int) $user->type !== ADMIN_TYPE) {
            return false;
        }

        if ($this->codesOf($user) !== []) {
            return false;
        }

        return $this->assign($user, $default);
    }

    /**
     * يشفي كلّ حسابات الإدارة التي بلا دور.
     *
     * @return int كم حساباً شُفي
     */
    public function healAdminsWithoutRoles(string $default = self::ADMIN): int
    {
        $orphans = DB::table('users')
            ->where('type', ADMIN_TYPE)
            ->whereNotIn('id', DB::table('admin_user_roles')->select('user_id'))
            ->pluck('id');

        $healed = 0;

        foreach ($orphans as $id) {
            $user = User::find($id);

            if ($user && $this->assign($user, $default)) {
                $healed++;
            }
        }

        return $healed;
    }
}
