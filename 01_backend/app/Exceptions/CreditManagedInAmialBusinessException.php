<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * AMIAL-BUSINESS-CREDIT-001 — حركة آجل لتاجر سلّم ديونه لأميال أعمال.
 *
 * يرثها RuntimeException عمداً: المتحكّمات القائمة التي تلتقط RuntimeException
 * تعرض رسالتها كما هي، وما لا يلتقطها يصيَّر هنا 409 برمز ثابت.
 */
class CreditManagedInAmialBusinessException extends RuntimeException
{
    public const CODE = 'CREDIT_MANAGED_IN_AMIAL_BUSINESS';

    public function __construct()
    {
        parent::__construct('ديون هذا المتجر تُدار الآن في «أميال أعمال»؛ سجّل البيع الآجل والتحصيل والمورّدين هناك.');
    }

    public function render(Request $request): ?JsonResponse
    {
        if (!($request->expectsJson() || $request->is('api/*'))) {
            return null;
        }

        return new JsonResponse([
            'success' => false,
            'code' => self::CODE,
            'message' => $this->getMessage(),
            'errors' => (object) [],
            'meta' => (object) [],
        ], 409);
    }
}
