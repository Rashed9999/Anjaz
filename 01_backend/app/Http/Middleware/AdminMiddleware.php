<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (Auth::guard('user')->check() && Auth::user()->type === ADMIN_TYPE) {
            return $next($request);
        }

        // **جلسةٌ على حارس `user` لغير مديرٍ تُنهى هنا، ولا تُترك فتُدوَّر.**
        //
        // صفحةُ دخول الإدارة عليها `guest:user`، فتردّ كلّ من له جلسةٌ على
        // هذا الحارس إلى `/admin`. وهذا الوسيط يردّ كلّ من ليس مديراً إلى
        // صفحة الدخول. فإذا وُجدت جلسةٌ لغير مديرٍ صار الاثنان يتقاذفان
        // الطلب إلى ما لا نهاية:
        //
        //     /admin → /admin/auth/login → /admin → …  (ERR_TOO_MANY_REDIRECTS)
        //
        // وتتعطّل لوحة الإدارة كلّها بلا سطرٍ واحدٍ في السجلّ.
        //
        // وقد وقع ذلك فعلاً حين كانت بوّابة الوكيل تفتح جلستها على الحارس
        // نفسه. وأُصلح مصدرُ التسرّب هناك — لكنّ الحلقة عيبٌ في هذا الوسيط
        // يبقى قائماً لأيّ تسرّبٍ آخر (عميلٌ أو تاجرٌ يدخل من الويب). فتُقطع
        // من طرفها: من ليس مديراً تُنهى جلسته قبل التحويل، فيرى صفحة الدخول.
        if (Auth::guard('user')->check()) {
            Auth::guard('user')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.auth.login')
                ->withErrors(['email' => 'هذه الجلسة ليست لحساب إدارة — سجّل الدخول بحساب الإدارة']);
        }

        return redirect()->route('admin.auth.login');
    }
}
