<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailIdentityService;
use App\Services\Kyc\LegalNameService;
use App\Services\LegalTermsService;
use App\Services\Otp\EmailOtpService;
use App\Services\ZoneAssignmentService;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use App\Services\Muwathaq\MuwathaqRegistrationContractService;
use App\Services\Muwathaq\MuwathaqOpeningProgressService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * AMIAL-QUICK-REG-001 — إنشاء محفظة أساسية من أربع معلومات فقط.
 *
 * الاسم + الهاتف + البريد + كلمة مرور دخول + PIN مالي مستقل.
 * لا هوية ولا سيلفي ولا دخل ولا PEP هنا. ملكية البريد تُثبت قبل الإنشاء،
 * وملكية الهاتف هي الخطوة التالية التي ترفع الحساب إلى Tier 1.
 * الحركة المالية تبقى خلف الإقامة الموثقة.
 */
class ProgressiveRegistrationController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly EmailIdentityService $identities,
        private readonly AuditService $audit,
        private readonly LegalTermsService $legalTerms,
        private readonly LegalNameService $legalNames,
        private readonly MuwathaqAccountOpeningService $muwathaq,
        private readonly MuwathaqRegistrationContractService $contracts,
        private readonly MuwathaqOpeningProgressService $openingProgress,
    ) {}

    public function register(Request $request): JsonResponse{
        return response()->json([
            'success' => false,
            'code' => 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ',
            'message' => 'إنشاء حساب العميل يتم حصراً عبر موثّق.',
            'muwathaq_url' => rtrim((string) config('muwathaq.base_url'), '/').'/open-account?type=customer',
        ], 410);
    }
}
