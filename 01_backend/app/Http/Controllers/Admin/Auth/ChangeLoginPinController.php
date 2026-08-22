<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\PlatformLoginPinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * AMIAL-AUTH-PIN-FORCE-002 — **شاشةُ تغيير رمز الدخول.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * بلا هذه الشاشة يكون الحاجزُ سجناً: يُمنع الموظّفُ من كلّ صفحةٍ ولا
 * يجد أين يُغيّر. **وحاجزٌ بلا مخرجٍ أسوأ من غيابه** — يشلّ ولا يحمي،
 * فيُطفَأ عند أوّل شكوى.
 */
class ChangeLoginPinController extends Controller
{
    public function __construct(
        private readonly PlatformLoginPinService $pins,
        private readonly AuditService $audit,
    ) {
    }

    public function show()
    {
        return view('admin-views.auth.change-pin');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_pin' => ['required', 'regex:/^\d{4}$/'],
            'new_pin' => ['required', 'regex:/^\d{4}$/', 'confirmed'],
        ], [
            'current_pin.regex' => 'الرمز الحاليّ أربعةُ أرقام.',
            'new_pin.regex' => 'الرمز الجديد أربعةُ أرقام.',
            'new_pin.confirmed' => 'الرمزان غير متطابقين.',
        ]);

        $user = auth('user')->user();

        // **ويُثبَت أنّه صاحبُ الرمز** — وإلّا صارت الشاشةُ بابَ استيلاءٍ
        // على جلسةٍ مسروقة: من جلس على جهازٍ مفتوحٍ يُبدّل الرمزَ ويُقفل
        // صاحبَه خارجاً.
        $check = $this->pins->verify($user, $data['current_pin']);

        if (! ($check['ok'] ?? false)) {
            return back()->withErrors(['current_pin' => 'الرمز الحاليّ غير صحيح.']);
        }

        // **ولا يُقبل الرمزُ نفسُه** — «غيّرتُه» التي لا تُغيّر شيئاً
        // تُطفئ الوسمَ وتُبقي الخطر، وهي أسوأ من عدم التغيير لأنّها
        // تُخفيه.
        if (Hash::check($data['new_pin'], (string) $this->pins->hashFor($user->id))) {
            return back()->withErrors(['new_pin' => 'الرمز الجديد هو نفسُه الحاليّ.']);
        }

        $this->pins->issue($user, $data['new_pin'], $user->id, 'self_change',
            mustChange: false, deliveryStatus: 'not_required');

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $user->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'PLATFORM_LOGIN_PIN_CHANGED',
            'decision_code' => 'SELF_CHANGE',
            'reason' => 'غيّر الموظّفُ رمزَ دخوله',
            'severity' => 'notice',
        ]);

        return redirect()->route('admin.dashboard')
            ->with('success', 'تم تغيير رمز الدخول.');
    }
}
