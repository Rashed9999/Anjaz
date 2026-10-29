<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Verticals\VerticalRegistry;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailIdentityService;
use App\Services\Kyc\LegalNameService;
use App\Services\LegalTermsService;
use App\Services\Merchant\MerchantNumberService;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use App\Services\Muwathaq\MuwathaqRegistrationContractService;
use App\Services\Muwathaq\MuwathaqOpeningProgressService;
use App\Services\Otp\EmailOtpService;
use App\Services\Vertical\VerticalBootstrapService;
use App\Services\ZoneAssignmentService;
use App\Support\Access\AccessConstants;
use App\Support\Phone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * AMIAL-MERCHANT-OPENING-002
 *
 * تسجيل المنشأة لا يمر بمعالج KYC القديم. القطاع يحدد تجهيز لوحة التاجر
 * وقدراتها فقط، بينما متطلبات الاعتماد يملكها موثّق بعقد مستقل.
 */
class MerchantAccountOpeningController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly EmailIdentityService $identities,
        private readonly LegalNameService $legalNames,
        private readonly LegalTermsService $legalTerms,
        private readonly MuwathaqAccountOpeningService $muwathaq,
        private readonly MuwathaqRegistrationContractService $contracts,
        private readonly MuwathaqOpeningProgressService $openingProgress,
        private readonly MerchantNumberService $merchantNumbers,
        private readonly VerticalBootstrapService $verticals,
        private readonly AuditService $audit,
    ) {}

    public function register(Request $request): JsonResponse{
        return response()->json([
            'success' => false,
            'code' => 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ',
            'message' => 'إنشاء حساب التاجر يتم حصراً عبر موثّق.',
            'muwathaq_url' => rtrim((string) config('muwathaq.base_url'), '/').'/open-account?type=merchant',
        ], 410);
    }
}
