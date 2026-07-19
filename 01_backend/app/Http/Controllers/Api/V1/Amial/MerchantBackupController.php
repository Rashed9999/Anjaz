<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\CorporateAccount;
use App\Models\CustomerCreditAccount;
use App\Models\Merchant;
use App\Models\MerchantCurrency;
use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-BACKUP-001 — نسخة احتياطية لبيانات التاجر (باقة التاجر برو فأعلى).
 * تُجمّع بيانات المتجر الأساسية في ملف JSON قابل للتنزيل.
 *
 *   GET /merchant/backup
 */
class MerchantBackupController extends Controller
{
    public function __construct(private FeatureAccessService $access) {}

    public function download(Request $request): JsonResponse
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return response()->json(['success' => false, 'message' => 'متاح للتجّار فقط'], 403);
        }
        if (!$this->access->hasFeature($u, A::F_ADVANCED_BACKUP)) {
            return response()->json(['success' => false, 'message' => 'النسخ الاحتياطي متاح في باقة التاجر برو فأعلى'], 402);
        }

        $merchant = Merchant::where('user_id', $u->id)->first();

        $backup = [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'merchant_user_id' => $u->id,
                'store_name' => $merchant?->store_name,
                'app' => 'أميال باي',
                'schema_version' => 1,
            ],
            'products' => MerchantProduct::where('merchant_user_id', $u->id)->get()->toArray(),
            'currencies' => MerchantCurrency::where('merchant_user_id', $u->id)->get()->toArray(),
            'credit_accounts' => CustomerCreditAccount::where('merchant_user_id', $u->id)
                ->with('movements')->get()->toArray(),
            'corporate_accounts' => CorporateAccount::where('merchant_user_id', $u->id)
                ->with(['members', 'movements'])->get()->toArray(),
            'sales' => MerchantSale::where('merchant_user_id', $u->id)
                ->orderByDesc('id')->limit(5000)->get()->toArray(),
        ];

        $counts = [
            'products' => count($backup['products']),
            'currencies' => count($backup['currencies']),
            'credit_accounts' => count($backup['credit_accounts']),
            'corporate_accounts' => count($backup['corporate_accounts']),
            'sales' => count($backup['sales']),
        ];
        $backup['meta']['counts'] = $counts;

        $filename = 'amial_backup_' . $u->id . '_' . now()->format('Ymd_His') . '.json';

        return response()->json($backup)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}
