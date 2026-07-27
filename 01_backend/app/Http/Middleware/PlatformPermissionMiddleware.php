<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-OPERATOR-RBAC-001 — يطلب صلاحية بعينها لا مجرّد كون الداخل مديراً.
 *
 * يُستعمل هكذا: `->middleware('platform:platform.customers.reset_pin')`.
 *
 * **ولماذا لا يكفي AdminMiddleware:** ذاك يجيب عن «هل هذا موظّف منصّة؟»،
 * وهذا يجيب عن «هل يحقّ له هذا الفعل؟». والسؤالان مختلفان، وخلطُهما هو ما
 * جعل موظّف الدعم قادراً على تصفير رمز عميل سرّي.
 *
 * **الرفض يُسجَّل.** محاولةٌ فاشلة لفعلٍ خارج الدور إمّا خطأٌ في إسناد
 * الأدوار — فيُصلَح — وإمّا محاولةُ تجاوز، وهي أوّل ما يُبحث عنه عند
 * التحقيق. وصمتُها يُضيّع الإشارتين معاً.
 */
class PlatformPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        // الأفعال نفسها متاحة عبر سطحين: لوحة الويب بجلسة، وواجهة API برمز.
        // وقراءةُ حارس الجلسة وحده تجعل الحارس على مسارات API يعيد التوجيه
        // بدل أن يفحص — فيمنع الجميع، بمن فيهم من يملك الصلاحية. (كشفه
        // اختبار السطح الثاني، ولولاه لبدا الحارس عاملاً وهو معطِّل.)
        $user = Auth::guard('user')->user() ?? Auth::guard('api')->user();

        if (!$user) {
            return $request->expectsJson()
                ? response()->json([
                    'success' => false,
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'الجلسة غير صالحة',
                    'errors' => (object)[],
                    'meta' => (object)[],
                ], 401)
                : redirect()->route('admin.auth.login');
        }

        if ($user->hasPlatformPermission($permission)) {
            return $next($request);
        }

        Log::warning('Platform permission denied', [
            'user_id' => $user->id,
            'roles' => $user->platformRoleLabels(),
            'needed' => $permission,
            'route' => $request->path(),
            'ip' => $request->ip(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => 'PERMISSION_DENIED',
                'message' => 'لا تملك صلاحية هذا الإجراء',
                'errors' => (object)[],
                'meta' => (object)['required' => $permission],
            ], 403);
        }

        abort(403, 'لا تملك صلاحية هذا الإجراء');
    }
}
