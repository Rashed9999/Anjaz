<?php

namespace App\Http\Middleware;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AGENT-PORTAL-001 / AMIAL-AGENT-STAFF-001 — بوّابة شركة الصرافة.
 *
 * **الهويّة تحدّد النطاق، لا القائمة المنسدلة.**
 *
 * أوّل صياغةٍ لهذه البوّابة جعلت الوكيل حساباً واحداً يختار الفرع من قائمةٍ
 * قبل كلّ عملية. وذلك تصميمُ محلٍّ واحد، لا تصميمُ شركةٍ مركزها الشحر ولها
 * آلاف الفروع وآلاف الموظّفين على كمبيوتراتٍ في فروعهم. وكان أثرُه أخطر من
 * إزعاج الواجهة: موظّفٌ واحدٌ يملك مفاتيح كلّ الفروع.
 *
 * فصار الدخول لثلاثة أدوار، ونطاقُ كلٍّ منها يُحسب هنا من حسابه:
 *
 *   • `head_office`    — الإدارة العامّة: كلّ فروع شركته، ولا تقف على شبّاك.
 *   • `branch_manager` — فرعُه وحده.
 *   • `teller`         — فرعُه وحده، وعملُه محصورٌ في ورديّته.
 *
 * ويبقى الدخول بحساب المستخدم (`User` من نوع وكيل) مقبولاً للتوافق مع
 * الشركات التي لم تُنشئ موظّفيها بعد — ويُعامَل معاملة الإدارة العامّة.
 */
class AgentPortalMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $staff = Auth::guard('agent_staff')->user();

        if ($staff instanceof AgentStaff) {
            if (!$staff->is_active) {
                Auth::guard('agent_staff')->logout();

                return $this->reject($request, 'الحساب معطَّل — راجع إدارة شركتك');
            }

            $request->attributes->set('agent_staff', $staff);
            $request->attributes->set('agent_root_id', (int) $staff->agent_user_id);
            $request->attributes->set('portal_role', $staff->role);
            $request->attributes->set('agent_branch_ids', $staff->visibleBranchIds());
            // الفرع الثابت: مصدرُ الحقيقة للشبّاك، ولا يأتي من الطلب أبداً.
            $request->attributes->set('agent_branch_id', $staff->branch_id ? (int) $staff->branch_id : null);
            $request->attributes->set('is_branch_account', !$staff->isHeadOffice());

            return $next($request);
        }

        $user = Auth::guard('user')->user();

        if (!$user) {
            return $this->reject($request, 'الجلسة غير صالحة');
        }

        if ((int) $user->type !== AGENT_TYPE) {
            abort(403, 'هذه البوّابة للوكلاء وفروعهم');
        }

        // حسابُ الوكيل نفسه (أو حساب فرع). يُحسب الجذر بالصعود لا بالافتراض.
        $parentId = DB::table('agent_profiles')->where('user_id', $user->id)->value('parent_agent_id');
        $isBranchAccount = $parentId !== null;

        $branchIds = $isBranchAccount
            ? AgentBranch::where('branch_user_id', $user->id)->pluck('id')->map(fn ($v) => (int) $v)->all()
            : AgentBranch::where('agent_user_id', $user->id)->pluck('id')->map(fn ($v) => (int) $v)->all();

        $request->attributes->set('agent_staff', null);
        $request->attributes->set('agent_root_id', $parentId ? (int) $parentId : (int) $user->id);
        $request->attributes->set('portal_role', $isBranchAccount
            ? AgentStaff::ROLE_BRANCH_MANAGER
            : AgentStaff::ROLE_HEAD_OFFICE);
        $request->attributes->set('agent_branch_ids', $branchIds);
        $request->attributes->set('agent_branch_id', $isBranchAccount ? ($branchIds[0] ?? null) : null);
        $request->attributes->set('is_branch_account', $isBranchAccount);

        return $next($request);
    }

    private function reject(Request $request, string $message): mixed
    {
        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message], 401)
            : redirect()->route('agent.login')->withErrors(['username' => $message]);
    }
}
