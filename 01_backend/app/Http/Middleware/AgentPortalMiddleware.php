<?php

namespace App\Http\Middleware;

use App\Models\Agent\AgentBranch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AMIAL-AGENT-PORTAL-001 — بوّابة الوكيل ليست لوحة الإدارة.
 *
 * **جمهورٌ آخر بصلاحياتٍ أخرى.** موظّف شركة الصرافة يدخل ليخدم عملاء فرعه،
 * لا ليرى المنصّة. ولذلك لا يكفي حارس الجلسة: يُشترط أن يكون **وكيلاً**،
 * وأن يُحصر ما يراه في فرعه أو فروعه هو.
 *
 * **وأخطر ما يمنعه هذا الحارس هو تسرّب البيانات بين الوكلاء:** شركةُ صرافةٍ
 * ترى أرصدة منافستها أو عملاءها كارثةٌ تجارية قبل أن تكون خرقاً أمنياً.
 * فيُحقَن في الطلب معرّفُ الوكيل، وتبني عليه كلّ استعلامات البوّابة.
 */
class AgentPortalMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'الجلسة غير صالحة'], 401)
                : redirect()->route('agent.login');
        }

        if ((int) $user->type !== AGENT_TYPE) {
            abort(403, 'هذه البوّابة للوكلاء وفروعهم');
        }

        // الفرع يرى نفسه، والوكيل الأمّ يرى فروعه.
        //
        // ويُحسب الجذر بالصعود لا بالافتراض: حسابٌ له `parent_agent_id`
        // فرعٌ، وما دونه وكيلٌ أمّ. وبلا هذا التمييز يرى موظّف الفرع خزائن
        // بقيّة الفروع.
        $parentId = \Illuminate\Support\Facades\DB::table('agent_profiles')
            ->where('user_id', $user->id)->value('parent_agent_id');

        $request->attributes->set('agent_root_id', $parentId ? (int) $parentId : (int) $user->id);
        $request->attributes->set('is_branch_account', $parentId !== null);

        $request->attributes->set(
            'agent_branch_ids',
            $parentId
                ? AgentBranch::where('branch_user_id', $user->id)->pluck('id')->all()
                : AgentBranch::where('agent_user_id', $user->id)->pluck('id')->all(),
        );

        return $next($request);
    }
}
