<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EncryptionService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * موثّق يسأل قبل أن يقبل طلب فتح الحساب: هل الهاتف أو البريد أو رقم الهوية مسجّل عندنا؟
 *
 * **يطابق ما سيرفضه التزويد لاحقاً حرفياً** (نفس استعلام الهاتف والبريد في MuwathaqProvisioningService):
 * كان العميل يرسل طلبه ويُعتمد ثم يرفضه أميال باي بـ422 فلا يصله شيء ولا يعرف السبب.
 *
 * **ما يخرج منه وجودٌ فقط** — لا اسم ولا هاتف ولا معرّف: القناة موقّعة بين الخادمين، ومع ذلك لا يخرج منها
 * ما يكشف شخصاً ثالثاً. ورقم الهوية يُرجَع منه **نوع** الحساب الحائز (عميل/تاجر/وكيل) لأن تطابقه مع حسابٍ
 * من نوعٍ آخر مشروع (تاجرٌ له حساب عميلٍ شخصيّ بهويّته) فيُقال ولا يُمنع.
 *
 * يظهر في : لا شاشة — قناة بين خادمين. نتيجتها في موثّق: رفض فوريّ للمتقدّم بسبب عربيّ، وبطاقة «الفحوص الآلية» للمراجع.
 */
final class MuwathaqIdentityCheckController extends Controller
{
    public function __construct(private readonly EncryptionService $enc) {}

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'identification_number' => 'nullable|string|max:40',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'code' => 'VALIDATION_FAILED', 'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object) []], 422);
        }

        $phone = trim((string) $request->input('phone', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $digits = preg_replace('/[^\d]/', '', EncryptionService::foldDigits(trim((string) $request->input('identification_number', ''))));

        $idChecked = mb_strlen((string) $digits) >= 5;
        $kinds = [];
        if ($idChecked) {
            $index = $this->enc->blindIndex((string) $digits, 'national_id');
            $idChecked = $index !== null;
            if ($index !== null) {
                $kinds = User::query()->where('national_id_blind_index', $index)->limit(20)->pluck('type')
                    ->map(fn ($t) => match ((int) $t) { (int) MERCHANT_TYPE => 'merchant', (int) AGENT_TYPE => 'agent', default => 'customer' })
                    ->unique()->values()->all();
            }
        }

        return response()->json(['success' => true, 'code' => 'MUWATHAQ_IDENTITY_CHECKED', 'data' => [
            'phone_exists' => $phone !== '' && User::query()->whereIn('phone', Phone::variants($phone))->exists(),
            'email_exists' => $email !== '' && User::query()->whereRaw('LOWER(email)=?', [$email])->exists(),
            'identification_checked' => $idChecked,
            'identification_kinds' => $kinds,
        ], 'meta' => (object) []]);
    }
}
