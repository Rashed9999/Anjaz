<?php

namespace App\Console\Commands;

use App\Models\EMoney;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelStation;
use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-DEMO-MERCHANTS-001
 *
 * حسابات تجّار تجريبية — واحد لكل قطاع، كلٌّ على الباقة التي تفتح ميزات قطاعه،
 * مع منتجات عيّنة (ومحطة وقود كاملة بمضخات وأسعار للتاجر الوقودي).
 *
 * الدخول (تبويب تاجر): رقم التاجر + الجوال + Pass@2026 — رمز PIN: 1237
 *
 *   AM-GROC-001  / 777200001  بقالة النور        (starter)
 *   AM-REST-002  / 777200002  مطعم الضيافة       (business)
 *   AM-PHAR-003  / 777200003  صيدلية الشفاء      (merchant_pro)
 *   AM-FUEL-004  / 777200004  محطة الأمل للوقود  (enterprise)
 *   AM-WHOL-005  / 777200005  النخبة للجملة      (merchant_pro)
 *
 * idempotent — يُعاد تشغيله مع كل نشر بأمان.
 */
class EnsureDemoMerchants extends Command
{
    protected $signature = 'amial:ensure-demo-merchants';
    protected $description = 'تهيئة حسابات تجّار تجريبية لكل قطاع مع الباقات والمنتجات';

    private const PASSWORD = 'Pass@2026';
    private const PIN = '1237';

    /** [phone, fName, lName, store, merchant_number, business_type, plan, products[]] */
    private const MERCHANTS = [
        [
            'phone' => '967777200001', 'f' => 'صالح', 'l' => 'النور',
            'store' => 'بقالة النور', 'number' => 'AM-GROC-001',
            'type' => 'retail', 'plan' => 'starter',
            'products' => [
                ['مياه شملان 750 مل', 'مشروبات', '6291000101', 180, 250, null, 120],
                ['خبز توست أبيض', 'مواد غذائية', '6291000102', 900, 1200, null, 25],
                ['قهوة يمنية فاخرة 250جم', 'مشروبات', '6291000103', 3200, 4500, 4000, 14],
                ['عسل سدر ملكي 500جم', 'مواد غذائية', '6291000104', 32000, 45000, null, 3],
            ],
        ],
        [
            'phone' => '967777200002', 'f' => 'هاشم', 'l' => 'الضيافة',
            'store' => 'مطعم الضيافة', 'number' => 'AM-REST-002',
            'type' => 'restaurant', 'plan' => 'business',
            'products' => [
                ['شاورما دجاج عائلي', 'وجبات', '7291000201', 3800, 6000, null, 999],
                ['برجر لحم فاخر', 'وجبات', '7291000202', 5200, 8000, null, 999],
                ['مقبلات مشكلة', 'مقبلات', '7291000203', 2600, 4500, null, 999],
                ['بيبسي 250 مل', 'مشروبات', '7291000204', 300, 500, null, 96],
            ],
        ],
        [
            'phone' => '967777200003', 'f' => 'أمين', 'l' => 'الشفاء',
            'store' => 'صيدلية الشفاء', 'number' => 'AM-PHAR-003',
            'type' => 'pharmacy', 'plan' => 'merchant_pro',
            'products' => [
                ['باراسيتامول 500 ملجم', 'أدوية', '8291000301', 600, 1000, null, 200],
                ['فيتامين سي فوّار', 'مكملات', '8291000302', 1800, 3000, 2500, 45],
                ['شراب كحة للأطفال', 'أدوية', '8291000303', 2200, 3500, null, 8],
                ['ضمادات طبية معقمة', 'مستلزمات', '8291000304', 500, 900, null, 60],
            ],
        ],
        [
            'phone' => '967777200004', 'f' => 'ماجد', 'l' => 'الأمل',
            'store' => 'محطة الأمل للوقود', 'number' => 'AM-FUEL-004',
            'type' => 'fuel', 'plan' => 'enterprise',
            'products' => [
                ['زيت محرك 4 لتر', 'زيوت', '9291000401', 14000, 19000, null, 30],
                ['ماء رديتر 1 لتر', 'سوائل', '9291000402', 900, 1500, null, 50],
            ],
        ],
        [
            // AMIAL: التاجر الحرّ (بيع سريع — بسطات/سمك/خضار) على الباقة المجانية
            'phone' => '967777200006', 'f' => 'أبو أحمد', 'l' => 'السمّاك',
            'store' => 'بسطة أبو أحمد للأسماك', 'number' => 'AM-FISH-006',
            'type' => 'quick_sale', 'plan' => 'free',
            'products' => [
                ['سمك ثمد طازج (كيلو)', 'أسماك', '9491000601', 2500, 3500, null, 40],
                ['جمبري كبير (كيلو)', 'أسماك', '9491000602', 6000, 8500, null, 15],
                ['سمك شعري (كيلو)', 'أسماك', '9491000603', 1800, 2500, null, 30],
            ],
        ],
        [
            'phone' => '967777200005', 'f' => 'وليد', 'l' => 'النخبة',
            'store' => 'النخبة للتجارة بالجملة', 'number' => 'AM-WHOL-005',
            'type' => 'wholesale', 'plan' => 'merchant_pro',
            'products' => [
                ['كرتون مياه شملان (24)', 'مشروبات', '9391000501', 4300, 5500, null, 400],
                ['كيس دقيق السنابل 10كجم', 'مواد غذائية', '9391000502', 9500, 12000, null, 150],
                ['كرتون تونة (48 علبة)', 'مواد غذائية', '9391000503', 52000, 65000, 62000, 35],
            ],
        ],
    ];

    public function handle(): int
    {
        foreach (self::MERCHANTS as $m) {
            try {
                $user = $this->ensureMerchantUser($m);
                $this->ensureMerchantRecord($user, $m);
                $this->ensureProfile($user, $m);
                $this->ensureWallet($user);
                $this->ensureProducts($user, $m['products']);
                if ($m['type'] === 'fuel') {
                    $this->ensureFuelStation($user, $m);
                }
                $this->info("✓ تاجر {$m['store']} جاهز — {$m['number']} / {$m['phone']} (باقة {$m['plan']})");
            } catch (\Throwable $e) {
                $this->error("❌ فشل تاجر {$m['store']}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function ensureMerchantUser(array $m): User
    {
        $shortPhone = substr($m['phone'], 3); // 777200001

        $existing = User::where('type', MERCHANT_TYPE)->get()->first(function ($u) use ($m, $shortPhone) {
            return in_array($u->phone, [$m['phone'], $shortPhone], true);
        });
        // AMIAL-DEMO-FIX: نفرض بيانات الدخول لحسابات التجربة (كلمة المرور/الرمز/
        // التفعيل/الدور) دون المساس بالرصيد أو النشاط — وإلا فشل الدخول بـ«بيانات
        // غير صحيحة» إن كان السرّ قديماً على النشر الحالي.
        if ($existing) {
            $existing->password = Hash::make(self::PASSWORD);
            $existing->transaction_pin = Hash::make(self::PIN);
            $existing->is_active = 1;
            if (Schema::hasColumn('users', 'role') && $existing->role !== 'merchant') {
                $existing->role = 'merchant';
            }
            $existing->save();
            return $existing;
        }

        $user = new User();
        $user->f_name = $m['f'];
        $user->l_name = $m['l'];
        $user->phone = $m['phone'];
        $user->type = MERCHANT_TYPE;
        $user->password = Hash::make(self::PASSWORD);
        $user->transaction_pin = Hash::make(self::PIN);
        $user->is_active = 1;
        if (Schema::hasColumn('users', 'is_kyc_verified')) {
            $user->is_kyc_verified = 1;
        }
        if (Schema::hasColumn('users', 'zone_code')) {
            $user->zone_code = 'SOUTH';
        }
        if (Schema::hasColumn('users', 'kyc_tier')) {
            $user->kyc_tier = 3;
        }
        $user->save();

        return $user;
    }

    private function ensureMerchantRecord(User $user, array $m): void
    {
        // Merchant (موديل 6cash) بلا $fillable — نُسنِد الخصائص مباشرة
        $rec = Merchant::where('user_id', $user->id)->first() ?? new Merchant();
        $rec->user_id = $user->id;
        $rec->store_name = $m['store'];
        $rec->merchant_number = $m['number'];
        $rec->address = 'عدن — المنصورة';
        $rec->save();
    }

    private function ensureProfile(User $user, array $m): void
    {
        MerchantProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'business_type' => $m['type'],
                'verification_status' => 'verified',
                'verified_at' => now(),
                'zone_code' => 'SOUTH',
                'subscription_plan' => $m['plan'],
                'subscription_expires_at' => now()->addDays(30),
                'subscription_notes' => 'حساب تجريبي — ' . $m['store'],
                'daily_receive_limit' => '5000000',
                'single_receive_limit' => '1000000',
                'monthly_receive_limit' => '50000000',
                'can_transfer_out' => true,
            ]
        );
    }

    private function ensureWallet(User $user): void
    {
        EMoney::firstOrCreate(
            ['user_id' => $user->id],
            [
                'current_balance' => '100000.0000',
                'charge_earned' => '0', 'pending_balance' => '0',
                'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 1,
            ]
        );
    }

    /** منتجات عيّنة: [name, category, barcode, cost, price, offer|null, qty]
     *  AMIAL-PERSIST: firstOrCreate — تُنشأ مرة واحدة فقط، فلا يُعاد ضبط الكمية
     *  (المخزون) مع كل نشر بعد بيع/جرد. تبقى كتطبيق حقيقي. */
    private function ensureProducts(User $user, array $products): void
    {
        foreach ($products as $p) {
            MerchantProduct::firstOrCreate(
                ['merchant_user_id' => $user->id, 'barcode' => $p[2]],
                [
                    'name' => $p[0],
                    'category' => $p[1],
                    'cost_price' => $p[3],
                    'price' => $p[4],
                    'offer_price' => $p[5],
                    'quantity' => $p[6],
                    'is_active' => true,
                ]
            );
        }
    }

    /** محطة كاملة: منتجات وقود بأسعار اللتر + 4 مضخات مرتبطة. */
    private function ensureFuelStation(User $user, array $m): void
    {
        $station = FuelStation::firstOrCreate(
            ['merchant_user_id' => $user->id],
            [
                'station_name' => $m['store'],
                'license_number' => 'FL-2026-004',
                'city' => 'عدن',
                'address' => 'طريق المطار',
                'is_active' => true,
                'zone_code' => 'SOUTH',
            ]
        );

        $fuels = [
            ['بترول', 'PET', '1450.0000', '#E6B84C'],
            ['ديزل', 'DSL', '1250.0000', '#14342A'],
            ['كيروسين', 'KRS', '1100.0000', '#5F6B62'],
        ];
        foreach ($fuels as $f) {
            FuelProduct::firstOrCreate(
                ['station_id' => $station->id, 'product_code' => $f[1]],
                [
                    'name' => $f[0],
                    'price_per_liter' => $f[2],
                    'color_hex' => $f[3],
                    'is_active' => true,
                ]
            );
        }

        for ($i = 1; $i <= 4; $i++) {
            FuelPump::firstOrCreate(
                ['station_id' => $station->id, 'pump_number' => $i],
                [
                    'pump_name' => "مضخة $i",
                    'pump_type' => $i <= 2 ? 'petrol' : 'diesel',
                    'is_active' => true,
                ]
            );
        }
    }
}
