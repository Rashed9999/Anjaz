<?php

namespace Database\Seeders;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\AccountNumberService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;

/**
 * AMIAL-LOAD — بيانات اختبار التحمّل (staging فقط — لا بيانات حقيقية، §25).
 *
 * يولّد:
 *   - عملاء + محافظ بأرصدة كبيرة (تكفي للتحويل/الدفع المتكرر).
 *   - تجّار + MerchantProfile.
 *   - يُصدّر tokens.json و merchants.json لـ k6.
 *
 * التشغيل (staging):
 *   php artisan db:seed --class=LoadTestSeeder
 * احذف البيانات بعد الاختبار: العملاء بـ phone يبدأ بـ +96770LT
 */
class LoadTestSeeder extends Seeder
{
    // العدد قابل للضبط عبر env لدعم مراحل k6 حتى 10000:
    //   LOADTEST_COUNT=10000 php artisan db:seed --class=LoadTestSeeder
    private const DEFAULT_COUNT = 10000;
    private const PIN = '0000';
    private const BALANCE = '100000000.0000'; // رصيد ضخم يكفي طوال الاختبار

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('مرفوض: لا يُشغَّل على production.');
            return;
        }

        $count = (int) env('LOADTEST_COUNT', self::DEFAULT_COUNT);
        // مجموعة تجّار POS كاملة التهيئة (لنجاح عمليات الكتابة في k6 مع POS_WRITES=1)
        $posPoolSize = min($count, (int) env('LOADTEST_POS_POOL', 50));

        $accSvc = app(AccountNumberService::class);
        $tokens = [];
        $merchants = [];
        $posPool = []; // [{user, token}] لأوائل التجّار — تُهيّأ بعد الحلقة

        $this->command->info("توليد {$count} عميل و{$count} تاجر...");

        for ($i = 0; $i < $count; $i++) {
            // عميل
            $customer = User::create([
                'f_name' => 'LoadCust', 'l_name' => (string)$i,
                'phone' => '+96770LT' . str_pad((string)$i, 6, '0', STR_PAD_LEFT),
                'password' => Hash::make('password'),
                'transaction_pin' => Hash::make(self::PIN),
                'type' => 2, 'zone_code' => 'SOUTH',
            ]);
            $this->wallet($customer->id);

            // تاجر
            $merchant = User::create([
                'f_name' => 'LoadMerch', 'l_name' => (string)$i,
                'phone' => '+96771LT' . str_pad((string)$i, 6, '0', STR_PAD_LEFT),
                'password' => Hash::make('password'),
                'type' => 3, 'zone_code' => 'SOUTH',
            ]);
            $this->wallet($merchant->id);
            MerchantProfile::create([
                'user_id' => $merchant->id,
                'verification_status' => 'verified',
            ]);

            // token للعميل + token للتاجر (Passport) — التاجر مطلوب لمزايا POS
            $token = $customer->createToken('loadtest')->accessToken;
            $merchantToken = $merchant->createToken('loadtest')->accessToken;

            $tokens[] = [
                'token' => $token,
                'account_number' => $customer->account_number,
                'pin' => self::PIN,
            ];
            $merchants[] = [
                'token' => $merchantToken,
                'account_number' => $merchant->account_number,
            ];

            if ($i < $posPoolSize) {
                $posPool[] = ['user' => $merchant, 'token' => $merchantToken];
            }

            if ($i % 500 === 0) $this->command->info("... {$i}");
        }

        // تهيئة مجموعة POS الكاملة (محطة+مضخّة+منتج+نوبة، صيدلية+منتج، جملة+عميل+منتج، كاشير+منتج)
        $this->command->info("تهيئة {$posPoolSize} تاجر POS كامل...");
        $pos = $this->provisionPosPool($posPool);

        // تصدير ملفات k6 (في storage/app)
        file_put_contents(storage_path('app/tokens.json'), json_encode($tokens, JSON_PRETTY_PRINT));
        file_put_contents(storage_path('app/merchants.json'), json_encode($merchants, JSON_PRETTY_PRINT));
        file_put_contents(storage_path('app/pos.json'), json_encode($pos, JSON_PRETTY_PRINT));

        $this->command->info('✓ تم. الملفات: storage/app/{tokens,merchants,pos}.json');
        $this->command->info('انسخها بجانب سكربت k6 ثم: k6 run -e POS_WRITES=1 loadtests/staged_all_features.js');
    }

    /**
     * يُهيّئ مجموعة تجّار POS كاملة باستخدام نفس خدمات المتحكمات (لضمان الصحّة)،
     * ويُرجع مصفوفة جاهزة لـ k6 تحوي توكن التاجر ومعرّفات الكيانات الحقيقية.
     *
     * @param  array<int, array{user: User, token: string}>  $poolMerchants
     * @return array<int, array<string, mixed>>
     */
    private function provisionPosPool(array $poolMerchants): array
    {
        $fuelSvc = app(\App\Services\FuelStationService::class);
        $shiftSvc = app(\App\Services\FuelShiftService::class);
        $pharmSvc = app(\App\Services\PharmacyService::class);
        $wholeSvc = app(\App\Services\WholesaleService::class);
        $cashSvc = app(\App\Services\CashierService::class);

        // عدد المنتجات/العملاء التجريبيين لكل تاجر (تنويع حمولات الكتابة)
        $prodCount = max(1, (int) env('LOADTEST_POS_PRODUCTS', 5));
        $custCount = max(1, (int) env('LOADTEST_POS_CUSTOMERS', 5));
        $fuelNames = ['بنزين 91', 'بنزين 95', 'ديزل', 'كاز', 'غاز'];

        $out = [];

        foreach ($poolMerchants as $idx => $entry) {
            /** @var User $merchant */
            $merchant = $entry['user'];

            // باقة enterprise (عمليات + منتجات غير محدودة) + نوع نشاط
            MerchantProfile::where('user_id', $merchant->id)->update([
                'subscription_plan' => \App\Support\Access\AccessConstants::PLAN_ENTERPRISE,
                'subscription_expires_at' => now()->addYear(),
                'business_type' => \App\Support\Access\AccessConstants::BIZ_RETAIL,
            ]);

            $record = ['token' => $entry['token']];

            // ---- وقود: محطة + عدّة منتجات + مضخّة + نوبة مفتوحة ----
            try {
                $station = $fuelSvc->getOrCreateStation($merchant, ['station_name' => "LoadStation {$idx}"]);
                $fuelIds = [];
                for ($p = 0; $p < $prodCount; $p++) {
                    $fp = $fuelSvc->addProduct($station, [
                        'name' => $fuelNames[$p % count($fuelNames)] . " {$p}",
                        'price_per_liter' => (string) (400 + $p * 25),
                    ]);
                    $fuelIds[] = $fp->id;
                }
                $pump = $fuelSvc->addPump($station, ['pump_number' => '1', 'pump_type' => 'mechanical', 'initial_meter_reading' => 0]);
                $shiftSvc->openShift($station, $merchant, '0', null);
                $record['fuel'] = ['pump_id' => $pump->id, 'fuel_product_ids' => $fuelIds];
            } catch (\Throwable $e) {
                $this->command->warn("fuel pool {$idx}: " . $e->getMessage());
            }

            // ---- صيدلية: صيدلية + عدّة منتجات ----
            try {
                $pharmacy = $pharmSvc->getOrCreatePharmacy($merchant, ['pharmacy_name' => "LoadPharmacy {$idx}"]);
                $pharmIds = [];
                for ($p = 0; $p < $prodCount; $p++) {
                    $pp = $pharmSvc->addProduct($pharmacy, [
                        'trade_name' => "Med-{$idx}-{$p}",
                        'sale_price' => (string) (10 + $p * 5),
                    ]);
                    $pharmIds[] = $pp->id;
                }
                $record['pharmacy'] = ['product_ids' => $pharmIds];
            } catch (\Throwable $e) {
                $this->command->warn("pharmacy pool {$idx}: " . $e->getMessage());
            }

            // ---- جملة: business + عدّة منتجات + عدّة عملاء ----
            try {
                $biz = $wholeSvc->getOrCreateBusiness($merchant, ['business_name' => "LoadWholesale {$idx}"]);
                $wProdIds = [];
                for ($p = 0; $p < $prodCount; $p++) {
                    $wp = $wholeSvc->addProduct($biz, [
                        'name' => "Bulk-{$idx}-{$p}",
                        'base_price' => (string) (50 + $p * 20),
                        'initial_stock' => '1000000',
                    ]);
                    $wProdIds[] = $wp->id;
                }
                $wCustIds = [];
                for ($c = 0; $c < $custCount; $c++) {
                    $wc = $wholeSvc->addCustomer($biz, ['full_name' => "LoadClient {$idx}-{$c}"]);
                    $wCustIds[] = $wc->id;
                }
                $record['wholesale'] = ['product_ids' => $wProdIds, 'customer_ids' => $wCustIds];
            } catch (\Throwable $e) {
                $this->command->warn("wholesale pool {$idx}: " . $e->getMessage());
            }

            // ---- كاشير: عدّة منتجات ----
            try {
                for ($p = 0; $p < $prodCount; $p++) {
                    $cashSvc->addProduct($merchant, ['name' => "CashItem-{$idx}-{$p}", 'price' => (string) (20 + $p * 10), 'quantity' => '1000000']);
                }
                $record['cashier'] = true;
            } catch (\Throwable $e) {
                $this->command->warn("cashier pool {$idx}: " . $e->getMessage());
            }

            $out[] = $record;
        }

        return $out;
    }

    private function wallet(int $userId): void
    {
        EMoney::create([
            'user_id' => $userId,
            'current_balance' => self::BALANCE,
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);
    }
}
