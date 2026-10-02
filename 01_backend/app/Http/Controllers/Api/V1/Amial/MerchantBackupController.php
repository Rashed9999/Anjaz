<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\CorporateAccount;
use App\Models\CustomerCreditAccount;
use App\Models\Merchant;
use App\Models\MerchantCurrency;
use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\MerchantProfile;
use App\Models\Pharmacy;
use App\Models\PharmacyProduct;
use App\Models\PharmacyBatch;
use App\Models\PharmacyCustomer;
use App\Models\PharmacySale;
use App\Models\FuelStation;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelCompanyAccount;
use App\Models\FuelSale;
use App\Models\RestaurantOrder;
use App\Models\RestaurantTable;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleProduct;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesaleCollection;
use App\Models\WholesaleReturn;
use App\Support\Access\AccessConstants as Access;
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
        $u = $request->user('merchant_web') ?? $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return response()->json(['success' => false, 'message' => 'متاح للتجّار فقط'], 403);
        }
        if (!$this->access->hasFeature($u, A::F_ADVANCED_BACKUP)) {
            return response()->json([
                'success' => false,
                'message' => 'النسخ الاحتياطي المتقدم متاح حسب باقة المنشأة',
            ], 402);
        }

        $merchant = Merchant::where('user_id', $u->id)->first();
        $profile = MerchantProfile::where('user_id', $u->id)->first();
        $vertical = (string) ($profile?->business_type ?: Access::BIZ_RETAIL);

        $backup = [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'merchant_user_id' => $u->id,
                'store_name' => $merchant?->store_name,
                'business_type' => $vertical,
                'app' => 'أميال باي',
                'schema_version' => 2,
                'sales_limit_per_source' => 5000,
                'warning_ar' => 'قد تحتوي النسخة على بيانات عملاء حساسة؛ احفظها في مكان آمن.',
            ],
            'merchant' => [
                'profile' => $profile?->toArray(),
                'store' => $merchant?->toArray(),
            ],
            'credit_accounts' => CustomerCreditAccount::where('merchant_user_id', $u->id)
                ->with('movements')->get()->toArray(),
            'corporate_accounts' => CorporateAccount::where('merchant_user_id', $u->id)
                ->with(['members', 'movements'])->get()->toArray(),
            'currencies' => MerchantCurrency::where('merchant_user_id', $u->id)->get()->toArray(),
            'vertical' => $this->verticalBackup($u->id, $vertical),
        ];

        $backup['meta']['counts'] = $this->backupCounts($backup);
        $filename = 'amial_backup_' . $u->id . '_' . now()->format('Ymd_His') . '.json';

        return response()->json($backup)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    private function verticalBackup(int $merchantUserId, string $vertical): array
    {
        if ($vertical === Access::BIZ_PHARMACY) {
            $pharmacy = Pharmacy::where('merchant_user_id', $merchantUserId)->first();
            if (!$pharmacy) return ['type' => $vertical, 'configured' => false];

            return [
                'type' => $vertical,
                'configured' => true,
                'pharmacy' => $pharmacy->toArray(),
                'products' => PharmacyProduct::where('pharmacy_id', $pharmacy->id)->get()->toArray(),
                'batches' => PharmacyBatch::whereHas('product',
                    fn ($q) => $q->where('pharmacy_id', $pharmacy->id))->get()->toArray(),
                'customers' => PharmacyCustomer::where('pharmacy_id', $pharmacy->id)->get()->toArray(),
                'sales' => PharmacySale::where('merchant_user_id', $merchantUserId)
                    ->orderByDesc('id')->limit(5000)->get()->toArray(),
            ];
        }

        if ($vertical === Access::BIZ_FUEL) {
            $station = FuelStation::where('merchant_user_id', $merchantUserId)->first();
            if (!$station) return ['type' => $vertical, 'configured' => false];

            return [
                'type' => $vertical,
                'configured' => true,
                'station' => $station->toArray(),
                'products' => FuelProduct::where('station_id', $station->id)->get()->toArray(),
                'pumps' => FuelPump::where('station_id', $station->id)->get()->toArray(),
                'company_accounts' => FuelCompanyAccount::where('station_id', $station->id)->get()->toArray(),
                'sales' => FuelSale::where('merchant_user_id', $merchantUserId)
                    ->orderByDesc('id')->limit(5000)->get()->toArray(),
            ];
        }

        if ($vertical === Access::BIZ_WHOLESALE) {
            $business = WholesaleBusiness::where('merchant_user_id', $merchantUserId)->first();
            if (!$business) return ['type' => $vertical, 'configured' => false];

            return [
                'type' => $vertical,
                'configured' => true,
                'business' => $business->toArray(),
                'products' => WholesaleProduct::where('business_id', $business->id)->get()->toArray(),
                'customers' => WholesaleCustomer::where('business_id', $business->id)->get()->toArray(),
                'invoices' => WholesaleInvoice::where('business_id', $business->id)
                    ->with('items')->orderByDesc('id')->limit(5000)->get()->toArray(),
                'collections' => WholesaleCollection::where('business_id', $business->id)
                    ->orderByDesc('id')->limit(5000)->get()->toArray(),
                'returns' => WholesaleReturn::where('business_id', $business->id)
                    ->with(['items', 'settlements'])->orderByDesc('id')->limit(5000)->get()->toArray(),
            ];
        }

        $data = [
            'type' => $vertical,
            'configured' => true,
            'products' => MerchantProduct::where('merchant_user_id', $merchantUserId)->get()->toArray(),
            'sales' => MerchantSale::where('merchant_user_id', $merchantUserId)
                ->with('lines')->orderByDesc('id')->limit(5000)->get()->toArray(),
        ];

        if ($vertical === Access::BIZ_RESTAURANT) {
            $data['tables'] = RestaurantTable::where('merchant_user_id', $merchantUserId)->get()->toArray();
            $data['orders'] = RestaurantOrder::where('merchant_user_id', $merchantUserId)
                ->orderByDesc('id')->limit(5000)->get()->toArray();
        }

        return $data;
    }

    private function backupCounts(array $backup): array
    {
        $vertical = $backup['vertical'] ?? [];
        $counts = [
            'credit_accounts' => count($backup['credit_accounts'] ?? []),
            'corporate_accounts' => count($backup['corporate_accounts'] ?? []),
            'currencies' => count($backup['currencies'] ?? []),
        ];

        foreach (['products', 'batches', 'customers', 'sales', 'pumps', 'company_accounts',
                  'invoices', 'collections', 'returns', 'tables', 'orders'] as $key) {
            if (isset($vertical[$key]) && is_array($vertical[$key])) {
                $counts[$key] = count($vertical[$key]);
            }
        }

        return $counts;
    }
}
