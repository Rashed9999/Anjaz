<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Support\Access\AccessConstants as A;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** صفحة الويب تعرض مصادر الخادم القائمة، ولا تُنشئ محرك مالٍ أو POS موازياً. */
class WebPortalController extends Controller
{
    public function index(Request $request): View
    {
        $owner = $request->user('merchant_web');
        $profile = MerchantProfile::where('user_id', $owner->id)->firstOrFail();
        $merchant = Merchant::where('user_id', $owner->id)->first();

        return view('merchant-web.dashboard', [
            'storeName' => $merchant?->store_name
                ?: trim((string) $owner->f_name . ' ' . (string) $owner->l_name),
            'businessType' => A::BUSINESS_TYPE_LABELS[$profile->business_type] ?? 'نشاط تجاري',
            'businessTypeCode' => (string) $profile->business_type,
            'plan' => A::PLAN_LABELS[A::canonicalPlan($profile->subscription_plan)] ?? 'مجاني',
        ]);
    }
}
