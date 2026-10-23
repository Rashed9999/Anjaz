<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Traits\UploadSizeHelperTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RegisterController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private User $user,
        private PhoneVerification $phoneVerification,
    ){}

    // $verifiedEmail is supplied ONLY by EmailRegistrationController after
    // atomic proof consumption; request input never authorizes this argument.
    public function customerRegistration(Request $request, ?string $verifiedEmail = null): JsonResponse{
        return response()->json([
            'success' => false,
            'code' => 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ',
            'message' => 'إنشاء حساب العميل يتم حصراً عبر موثّق.',
            'muwathaq_url' => rtrim((string) config('muwathaq.base_url'), '/').'/open-account?type=customer',
        ], 410);
    }

    /**
     * AMIAL-SIGNATURE-001 — يفكّ توقيعاً إلكترونياً (base64 PNG/JPEG، data-URI أو خام)،
     * يتحقّق أنّه صورة صالحة ضمن حدّ الحجم، ثمّ يخزّنه *مشفّراً* عبر EncryptedFileStorage.
     *
     * @return string|null المسار المشفّر، أو null لو غاب/غير صالح.
     */
    private function storeSignature(?string $base64): ?string
    {
        if (empty($base64)) {
            return null;
        }

        // اقبل data-URI (data:image/png;base64,xxxx) أو base64 خام
        if (preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $base64)) {
            $base64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $base64);
        }
        $binary = base64_decode(strtr(trim($base64), ' ', '+'), true);
        if ($binary === false || strlen($binary) < 64) {
            return null; // ليس base64 صالحاً
        }
        if (strlen($binary) > 2_097_152) {
            return null; // > 2MB — توقيع لا يحتاج أكثر
        }
        // تأكّد أنّه صورة فعلية (لا ملفّ ضارّ)
        $info = @getimagesizefromstring($binary);
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'amial_sig_');
        try {
            file_put_contents($tmp, $binary);
            return app(\App\Services\EncryptedFileStorage::class)
                ->encryptAndStore($tmp, 'signatures');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Signature] store failed', ['error' => $e->getMessage()]);
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    public function agentRegistration(Request $request): JsonResponse{
        return response()->json([
            'success' => false,
            'code' => 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ',
            'message' => 'إنشاء حساب الوكيل يتم حصراً عبر موثّق.',
            'muwathaq_url' => rtrim((string) config('muwathaq.base_url'), '/').'/open-account?type=agent',
        ], 410);
    }

    /**
     * AMIAL-SELFREG-KYCDOCS-001 — **نظامان لا يلتقيان، وزرُّ الاعتماد بينهما.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ما قِيس:** التسجيلُ الذاتيُّ يحفظ صورَ الوثائق في
     * `users.identification_image` (‏JSON من مسارات)، و
     * `KycDocumentService::decideAccountVerification` — التي يمرّ بها
     * **زرُّ الاعتماد في لوحة التحقّق** — تشترط صفوفاً في `kyc_documents`
     * من ثلاثة أنواع: وجهُ الهوية · ظهرُها · صورةٌ شخصيّة.
     *
     * فالزرُّ يردّ ٤٢٢ «لا يُعتمد الحسابُ قبل رفع هذه المستندات» على حسابٍ
     * **رفع وثائقَه فعلاً**، ولا سبيلَ في تلك الشاشة إلى رفعها. أي أنّ
     * **كلَّ حسابٍ سجّل ذاتيّاً — عميلاً أو تاجراً أو وكيلاً — لا يمكن
     * اعتمادُه أبداً.** (قِيس بالتشغيل: `SelfRegisteredMerchantIsUsableTest`.)
     *
     * **ولمَ لم يمسكه اختبار:** كلُّ اختبارات الاعتماد ترفع المستنداتِ
     * بيدها عبر `upload()` ثمّ تعتمد — فتفحص المنطقَ وتتخطّى الفجوة.
     * والفجوةُ ليست في طرفٍ منهما بل **في الوصلة بينهما**.
     *
     * ══════════════════════════════════════════════════════════════════
     * **والوصلُ يحتاج نوعاً، والنوعُ لا يُخمَّن.** فمستندٌ يُسجَّل «وجهَ
     * هويّة» وهو ظهرُها يُفسد ملفَّ امتثالٍ بصمت، وهو أسوأُ من غيابه.
     * فتُقبل الحقولُ **مسمّاةً** (`kyc_id_front` …)، والتطبيقُ يرسلها في
     * خاناتٍ معنونة. وما لا نوعَ له يبقى حيث هو ولا يُخترَع له نوع
     * (القاعدة السابعة: «غير معروف» ليس قيمةً تُملأ).
     *
     * **ولا يُسقِط فشلُ مستندٍ تسجيلاً**: الحسابُ أُنشئ، وفقدُ مستندٍ
     * يُعالَج برفعه ثانيةً — لا بإلغاء الحساب. فيُلتقط كلُّ استثناءٍ
     * ويُسجَّل، ويمضي التسجيل.
     */
    private function ingestKycDocuments(\App\Models\User $user, Request $request): void
    {
        $map = [
            'kyc_id_front' => \App\Models\KycDocument::TYPE_ID_FRONT,
            'kyc_id_back' => \App\Models\KycDocument::TYPE_ID_BACK,
            'kyc_selfie' => \App\Models\KycDocument::TYPE_SELFIE,
            'kyc_address_proof' => \App\Models\KycDocument::TYPE_ADDRESS_PROOF,
        ];

        $svc = app(\App\Services\KycDocumentService::class);

        foreach ($map as $field => $docType) {
            if (!$request->hasFile($field)) {
                continue;
            }
            try {
                $svc->upload($user, $docType, $request->file($field));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'AMIAL-SELFREG-KYCDOCS-001: تعذّر إدراج مستند التسجيل',
                    ['user_id' => $user->id, 'doc_type' => $docType, 'error' => $e->getMessage()],
                );
            }
        }
    }
}
