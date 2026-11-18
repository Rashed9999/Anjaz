<?php

namespace App\Http\Middleware;

use App\Models\Agent\AgentStaff;
use App\Support\Agent\AgentCompanyStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AMIAL-AGENT-PORTAL-001 / AMIAL-AGENT-STAFF-001 — بوّابة شركة الصرافة.
 *
 * **الهويّة تحدّد النطاق، لا القائمة المنسدلة.**
 *
 *   • `head_office`    — الإدارة العامّة: كلّ فروع شركته، ولا تقف على شبّاك.
 *   • `branch_manager` — فرعُه وحده.
 *   • `teller`         — فرعُه وحده، وعملُه محصورٌ في ورديّته.
 */
class AgentPortalMiddleware
{
    public const SESSION_PASSWORD_FP_KEY = 'agent_staff_pw_fp';

    public static function passwordFingerprint(AgentStaff $staff): string
    {
        return hash('sha256', (string) $staff->password);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $staff = Auth::guard('agent_staff')->user();

        if ($staff instanceof AgentStaff) {
            // قراءة أحدث صف من القاعدة دائماً لضمان نفاذ التعطيل وتصفير كلمة المرور فوراً.
            $staff = AgentStaff::find($staff->id);

            if (!$staff || !$staff->is_active) {
                return $this->terminateSession($request, 'الحساب معطَّل — راجع إدارة شركتك');
            }

            // AGT-GAP-013 — تصفير كلمة المرور من المدير يُبطل الجلسة المفتوحة فوراً.
            if ($request->hasSession() && $request->session()->has(self::SESSION_PASSWORD_FP_KEY)) {
                $expected = (string) $request->session()->get(self::SESSION_PASSWORD_FP_KEY);
                if (!hash_equals($expected, self::passwordFingerprint($staff))) {
                    return $this->terminateSession($request, 'انتهت الجلسة لتغيير كلمة المرور — أعد الدخول');
                }
            }

            // AGT-GAP-006 — إيقاف شركة الصرافة يطرد جلسات موظفيها فوراً.
            $companyStatus = AgentCompanyStatus::inspect((int) $staff->agent_user_id);
            if (!$companyStatus['operable']) {
                return $this->terminateSession($request, (string) $companyStatus['reason']);
            }

            // AGT-GAP-005 — ليرى EnforceIdempotency وبقية الوسطاء حارس الموظفين لا user.
            Auth::shouldUse('agent_staff');

            $request->attributes->set('agent_staff', $staff);
            $request->attributes->set('agent_root_id', (int) $staff->agent_user_id);
            $request->attributes->set('portal_role', $staff->role);
            $request->attributes->set('agent_branch_ids', $staff->visibleBranchIds());
            // الفرع الثابت: مصدرُ الحقيقة للشبّاك، ولا يأتي من الطلب أبداً.
            $request->attributes->set('agent_branch_id', $staff->branch_id ? (int) $staff->branch_id : null);
            $request->attributes->set('is_branch_account', !$staff->isHeadOffice());

            return $next($request);
        }

        // **حارسٌ واحدٌ لا حارسان.**
        return $this->reject($request, 'الجلسة غير صالحة');
    }

    private function terminateSession(Request $request, string $message): mixed
    {
        Auth::guard('agent_staff')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->reject($request, $message);
    }

    private function reject(Request $request, string $message): mixed
    {
        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message], 401)
            : redirect()->route('agent.login')->withErrors(['username' => $message]);
    }
}
