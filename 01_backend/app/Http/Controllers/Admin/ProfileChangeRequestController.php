<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Kyc\IdentityExpiryService;
use App\Services\Kyc\ProfileChangeRequestService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-PROFILE-CHANGE-003 — **شاشةُ طلبات تحديث البيانات.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وخدمةٌ بلا مدخلٍ ليست مبنيّة.** هذا المتحكّمُ هو ما يجعل
 * `ProfileChangeRequestService` شيئاً يُستعمَل لا صنفاً في مجلَّد.
 *
 * **ولا `update` ولا `edit` في هذا الملفّ عمداً**: أفعالُه `open` (يفتح
 * طلباً) و`decide` (يعتمد أو يرفض). **ولا فعلَ واحدٌ يكتب قيمةً يُدخلها
 * الموظّف** — القيمةُ من صاحب الحساب وحدَه، وهذا هو الفرقُ كلُّه بين
 * «طلبِ تحديث» و«زرِّ تعديل».
 */
class ProfileChangeRequestController extends Controller
{
    public function __construct(
        private readonly ProfileChangeRequestService $requests,
        private readonly IdentityExpiryService $expiry,
    ) {
    }

    public function page(Request $request)
    {
        return view('admin-views.amial.kyc.change-requests', [
            'queue' => $this->requests->pendingQueue(),
            'fields' => ProfileChangeRequestService::CHANGEABLE,
            'needsDocument' => ProfileChangeRequestService::NEEDS_DOCUMENT,
            'resetsVerification' => ProfileChangeRequestService::RESETS_VERIFICATION,
        ]);
    }

    // **ولا نقطةَ `queue` ها هنا.** بُنيت أوّلَ مرّةٍ ثمّ أمسكها
    // `AdminPanelDeadEndpointGuardTest`: تكرارٌ لِما تعرضه الصفحةُ من
    // الخادم أصلاً، **ولا قالبَ يناديها**. فحُذفت ولم تُعفَ — والإعفاءُ
    // على شيفرةٍ ميّتةٍ يُبقيها ويُسكت الحارس.

    /** الدعمُ يفتح طلباً نيابةً عن العميل — ولا يملأ قيمةً. */
    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'field' => ['required', 'string', 'max:60'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.min' => 'اذكر سببَ الطلب — وطلبٌ بلا سببٍ لا يُراجَع.',
        ]);

        try {
            $this->requests->open(
                User::findOrFail($data['user_id']),
                $data['field'],
                (int) $request->user()->id,
                'admin',
                $data['reason'],
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success',
            'فُتح الطلب. **ويملؤه العميلُ من التطبيق** — لا يُكتب من هنا.');
    }

    /** مراجعٌ يحسم — والحمايةُ في الخدمة لا في الشاشة. */
    public function decide(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->requests->decide(
                $id, $request->user(),
                $data['decision'] === 'approve',
                $data['reason'] ?? null,
            );
        } catch (DomainException $e) {
            // **ورسالةُ المبدأ الرباعيّ تُترجَم** — رمزٌ إنجليزيٌّ في
            // شاشةٍ عربيّةٍ يُوقف القارئَ ولا يقول ما يفعل.
            return back()->with('error', $e->getMessage() === 'FOUR_EYES_VIOLATION'
                ? 'لا يعتمد المرءُ طلباً فتحه ولا طلباً يخصّ حسابَه — يحسمه مراجعٌ آخر.'
                : $e->getMessage());
        }

        return back()->with('success', 'حُسم الطلب.');
    }

    /**
     * حالةُ هويّة حسابٍ واحد — **يُقرأ التاريخُ الذي كان يُجمَع ولا يُقرأ**.
     */
    public function identityState(int $userId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->expiry->stateOf(User::findOrFail($userId)),
            'errors' => (object) [], 'meta' => (object) [],
        ]);
    }
}
