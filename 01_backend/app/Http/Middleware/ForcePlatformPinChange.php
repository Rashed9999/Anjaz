<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AUTH-PIN-FORCE-001 — **وسمٌ يُعرَض ولا يمنع ليس حاجزاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل، وقد أُثبت بالتشغيل لا بالقراءة:**
 *
 *     must_change في القاعدة: 1
 *     حالةُ الردّ: 302  →  /admin
 *     أدخل؟ نعم — بلا مطالبةٍ بالتغيير
 *     واللوحةُ تُفتح؟ نعم
 *
 * عمودُ `must_change` يُكتب في ثلاثة مواضع، **ولا يقرؤه إلّا شارةٌ صفراءُ
 * في شاشة الأدوار** («PIN أولي — غيّره»). ولا سطرَ واحدٌ في مسار الدخول
 * يفحصه.
 *
 * **والأثرُ على أخطر حساب:** المشرفُ الجذر — أعلى صلاحيّةٍ في المنصّة،
 * ويملك `platform.money.move` وحدَه — يدخل بـ**`1234`** إلى الأبد. ورمزُ
 * الإقلاع مكتوبٌ في هجرةٍ في المستودع، فهو معروفٌ لكلّ من يقرأ الشيفرة.
 *
 * **وهو نمطُ العطل الأكثرُ تكراراً في هذا المشروع** — مبنيٌّ ولا يُوصَل
 * إليه — واقعاً على أخطر باب. والوسمُ الأصفرُ يجعله أسوأ: **يُوهم بأنّ
 * الأمرَ مضبوطٌ فلا يبحث أحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ وسيطةٌ على المجموعة لا فحصٌ في المتحكّم:**
 *
 * الدخولُ بابٌ واحدٌ واللوحةُ مئةٌ وستّون صفحة. وفحصٌ عند الدخول وحدَه
 * يتركُ جلسةً قائمةً تعمل بعد أن يُوسَم الرمزُ «يجب تغييره» — ومن أُعيد
 * تعيينُ رمزِه لاشتباهٍ يبقى داخلاً حتّى يخرج بنفسه.
 *
 * فالفحصُ **في كلّ طلب**: صفٌّ واحدٌ مفهرَسٌ بالمفتاح الأوّل.
 */
class ForcePlatformPinChange
{
    /** المساراتُ التي يجب أن تبقى مفتوحةً — وإلّا صارت حلقةَ توجيهٍ مغلقة. */
    private const ALWAYS_ALLOWED = [
        'admin.auth.pin.change',
        'admin.auth.pin.update',
        'admin.auth.logout',
        'admin.auth.login',
        'admin.auth.two-factor',
        'admin.auth.two-factor.verify',
        'admin.auth.two-factor.cancel',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = auth('user')->user();

        if ($user === null) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();

        if (in_array($name, self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        // ══════════════════════════════════════════════════════════════
        // **ومسارٌ ثانٍ للفعل نفسِه — يُستثنى بفعله لا بعنوانه.**
        //
        // شاشةُ الأدوار تحمل تغييرَ الموظّفِ رمزَ نفسِه ضمن
        // `operator_action`. **وحاجزٌ يمنعه يمنع الفعلَ الذي يطالب به** —
        // فيصير سجناً لا حاجزاً.
        //
        // **ولا يُفتَح المسارُ كلُّه**: هو نفسُه يُسنِد الأدوارَ ويُعيد
        // تعيينَ رموزِ الآخرين. فالاستثناءُ على قيمة الفعل وحدَها،
        // ومتنُ المتحكّم يشترط أن يكون الفاعلُ صاحبَ الحساب ويطلب الرمزَ
        // الحاليّ — قِيس ولم يُفترَض.
        if ($name === 'admin.amial.ops.roles.update'
            && $request->input('operator_action') === 'change_own_login_pin') {
            return $next($request);
        }

        // **ولا يُقاس بجدولٍ قد لا يوجد.** الهجرةُ قد لا تكون شُغّلت في
        // بيئةٍ قديمة، وحاجزٌ ينهار على غياب جدولٍ يُقفل اللوحةَ كلَّها.
        if (! \Illuminate\Support\Facades\Schema::hasTable('platform_login_pins')) {
            return $next($request);
        }

        $mustChange = DB::table('platform_login_pins')
            ->where('user_id', $user->id)->value('must_change');

        if (! $mustChange) {
            return $next($request);
        }

        // **والردُّ يقول لماذا.** إعادةُ توجيهٍ صامتةٌ تُقرأ عطلاً.
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => 'PIN_CHANGE_REQUIRED',
                'message' => 'رمزُ الدخول أوّليٌّ — غيّره قبل متابعة العمل.',
                'errors' => (object) [],
                'meta' => (object) [],
            ], 403);
        }

        return redirect()->route('admin.auth.pin.change')
            ->with('warning', 'رمزُ دخولك أوّليٌّ ويجب تغييره قبل متابعة العمل.');
    }
}
