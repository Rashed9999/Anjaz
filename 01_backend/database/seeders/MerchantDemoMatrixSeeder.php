<?php

namespace Database\Seeders;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * AMIAL-DEMO-MERCHANT-MATRIX-001
 *
 * مصفوفة حسابات ثابتة لاختبار واجهات التاجر والاستحقاقات:
 * ٦ أنواع نشاط × الباقات المُباعة (`A::ALL_PLANS`) — والعددُ يُشتقّ ولا
 * يُكتب. كان مكتوباً «٥ باقات = ٣٠ حساباً»، ثمّ وحّد صاحبُ المشروع
 * الباقاتِ ثلاثاً فصارت ثمانيةَ عشر، **وبقي الرقمُ ثلاثين في الوصف
 * وفي سطر البذر وفي الوثيقة** — انظر AMIAL-PLAN-MATRIX-003 أدناه.
 *
 * لا تُضاف إلى DatabaseSeeder عمداً. في production لا تعمل إلا بعد تفعيل
 * AMIAL_ALLOW_PRODUCTION_DEMO_MERCHANTS=true بشكل صريح، لأن كلمات المرور
 * معروفة ومخصصة للاختبار فقط.
 *
 * الحسابات settlement-safe افتراضياً: لها رصيد عرض لكن لا يُسمح لها
 * بالتحويل الخارجي. أي اختبار مالي E2E يحتاج حساباً مخصصاً منفصلاً.
 */
class MerchantDemoMatrixSeeder extends Seeder
{
    public const PASSWORD = 'Merchant@2026';
    public const PIN = '1234';
    public const BALANCE_YER = 10_000_000;
    public const MARKER = 'AMIAL_DEMO_MERCHANT_MATRIX_V1';

    /** @var array<string,array{digit:string,label:string}> */
    private const BUSINESS_TYPES = [
        A::BIZ_QUICK_SALE => ['digit' => '1', 'label' => 'بيع سريع'],
        A::BIZ_RETAIL => ['digit' => '2', 'label' => 'تجزئة'],
        A::BIZ_FUEL => ['digit' => '3', 'label' => 'محطة وقود'],
        A::BIZ_PHARMACY => ['digit' => '4', 'label' => 'صيدلية'],
        A::BIZ_WHOLESALE => ['digit' => '5', 'label' => 'جملة'],
        A::BIZ_RESTAURANT => ['digit' => '6', 'label' => 'مطعم'],
    ];

    /**
     * AMIAL-PLAN-MATRIX-003 — **ثلاثُ باقاتٍ تُعلَن كما تُبذَر.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كانت هنا **خمسةُ صفوف**، وفيها `A::PLAN_STARTER` و
     * `A::PLAN_MERCHANT_PRO` — **وهما مُرادفان** لا باقتان:
     * `PLAN_STARTER === PLAN_BUSINESS` و`PLAN_MERCHANT_PRO === PLAN_ENTERPRISE`.
     *
     * فالمفتاحُ `'business'` كُتب مرّتين و`'enterprise'` مرّتين، و**PHP
     * يُبقي الأخيرَ صامتاً بلا تحذير**. فكانت الشيفرةُ **تُقرأ خمساً
     * وتعمل ثلاثاً**، ورقمُ باقةِ «البداية» (١) يُمحى بـ«الأعمال» (٢)
     * قبل أن يُستعمل.
     *
     * **والخانةُ الأخيرة بقيت كما كانت عمداً — ٠ · ٢ · ٤:** أرقامُ حسابات
     * العرض محفوظةٌ عن ظهر قلب، فترقيمُها من جديدٍ يُدخل من حفظ رقماً
     * على باقةٍ غيرِ التي يقصد. و`…001` و`…003` سقطا وحدَهما.
     * (المحروس في `MerchantDemoMatrixTest`.)
     *
     * **والاسمُ يُقرأ من `A::PLAN_LABELS`** فلا يتفرّع اسمُ باقةٍ نسختين:
     * واحدةً في شاشةِ الباقات وأخرى في باذر العرض.
     *
     * @var array<string,array{digit:string}>
     */
    private const PLANS = [
        A::PLAN_FREE => ['digit' => '0'],
        A::PLAN_BUSINESS => ['digit' => '2'],
        A::PLAN_ENTERPRISE => ['digit' => '4'],
    ];

    /**
     * مصدر واحد للحسابات، ويُستخدم أيضاً في الاختبارات والتوثيق.
     *
     * الرقم المحلي = 77721 + رقم القطاع + 00 + رقم الباقة.
     * مثال الجملة/الأعمال: 777215002، والمخزن في DB: 967777215002.
     *
     * @return list<array<string,string|int>>
     */
    public static function accounts(): array
    {
        $rows = [];

        foreach (self::BUSINESS_TYPES as $businessType => $business) {
            foreach (self::PLANS as $plan => $planMeta) {
                $localPhone = '77721' . $business['digit'] . '00' . $planMeta['digit'];
                $rows[] = [
                    'business_type' => $businessType,
                    'business_label' => $business['label'],
                    'plan' => $plan,
                    'plan_label' => A::PLAN_LABELS[$plan] ?? $plan,
                    'phone_local' => $localPhone,
                    'phone' => '967' . $localPhone,
                    'password' => self::PASSWORD,
                    'pin' => self::PIN,
                    'balance_yer' => self::BALANCE_YER,
                ];
            }
        }

        return $rows;
    }

    public function run(): void
    {
        $this->assertSafeEnvironment();

        foreach (self::accounts() as $row) {
            $this->assertNoRealAccountCollision((string) $row['phone']);
            $user = $this->upsertMerchant($row);
            $this->upsertMerchantProfile($user, $row);
            $this->upsertWallet($user);
        }

        // **والعددُ يُعدّ ولا يُكتب.** كان «30 accounts» مطبوعاً بعد بذر
        // ثمانيةَ عشر — ورقمٌ مكتوبٌ في رسالةِ نجاحٍ يشيخ مع أوّل قرارِ
        // تسعيرٍ ثمّ يُقرأ إثباتاً. (AMIAL-PLAN-MATRIX-003)
        $rows = self::accounts();

        $this->command?->info(sprintf(
            '✓ AMIAL merchant demo matrix: %d accounts ready (%d verticals × %d plans).',
            count($rows), count(self::BUSINESS_TYPES), count(self::PLANS)));
        $this->command?->info('  Password: ' . self::PASSWORD . ' | Transaction PIN: ' . self::PIN);

        $wholesale = array_filter($rows,
            fn (array $r): bool => $r['business_type'] === A::BIZ_WHOLESALE);

        $this->command?->info('  Wholesale: ' . implode(' · ', array_map(
            fn (array $r): string => $r['phone_local'] . ' (' . $r['plan_label'] . ')',
            $wholesale)));
    }

    private function assertSafeEnvironment(): void
    {
        if (!app()->environment('production')) {
            return;
        }

        $allowed = filter_var(
            env('AMIAL_ALLOW_PRODUCTION_DEMO_MERCHANTS', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        if (!$allowed) {
            throw new RuntimeException(
                'Refusing to seed known-password demo merchants in production. '
                . 'Set AMIAL_ALLOW_PRODUCTION_DEMO_MERCHANTS=true only for an explicitly approved demo/test deployment.'
            );
        }
    }

    private function assertNoRealAccountCollision(string $phone): void
    {
        $existing = User::where('phone', $phone)->first();
        if (!$existing) {
            return;
        }

        $profile = MerchantProfile::where('user_id', $existing->id)->first();
        $notes = (string) ($profile?->subscription_notes ?? '');

        if (!str_contains($notes, self::MARKER)) {
            throw new RuntimeException(
                "Demo phone {$phone} is already owned by a non-demo account; refusing to overwrite it."
            );
        }
    }

    /** @param array<string,string|int> $row */
    private function upsertMerchant(array $row): User
    {
        return User::unguarded(function () use ($row): User {
            return User::updateOrCreate(
                ['phone' => (string) $row['phone']],
                [
                    'f_name' => (string) $row['business_label'],
                    'l_name' => 'تجريبي — ' . (string) $row['plan_label'],
                    'password' => Hash::make(self::PASSWORD),
                    'transaction_pin' => self::PIN,
                    'type' => 3,
                    'role' => A::ROLE_MERCHANT,
                    'verification_level' => A::VERIFICATION_PREMIUM,
                    'zone_code' => 'SOUTH',
                    'is_active' => 1,
                    'is_phone_verified' => 1,
                    'is_kyc_verified' => 1,
                    'kyc_tier' => 3,
                    'kyc_tier_updated_at' => now(),
                    'sanction_checked' => 1,
                    'sanction_status' => 'clear',
                    'requires_pin_setup' => false,
                    'pin_failed_attempts' => 0,
                    'pin_locked_until' => null,
                    'security_hold_until' => null,
                    'security_hold_reason' => null,
                ],
            );
        });
    }

    /** @param array<string,string|int> $row */
    private function upsertMerchantProfile(User $user, array $row): void
    {
        MerchantProfile::updateOrCreate(
            ['user_id' => $user->id],
            array_merge(
                MerchantProfile::defaultLimitsForTier('large'),
                [
                    'tier' => 'large',
                    'risk_category' => 'standard',
                    'business_type' => (string) $row['business_type'],
                    'verification_status' => 'verified',
                    'subscription_plan' => (string) $row['plan'],
                    'subscription_expires_at' => null,
                    'subscription_notes' => self::MARKER
                        . ' | ' . (string) $row['business_type']
                        . ' | ' . (string) $row['plan'],
                    'extra_features' => [],
                    'can_transfer_out' => false,
                    'requires_settlement_only' => true,
                    'zone_code' => 'SOUTH',
                    'verified_at' => now(),
                ],
            ),
        );
    }

    private function upsertWallet(User $user): void
    {
        EMoney::updateOrCreate(
            ['user_id' => $user->id],
            [
                'current_balance' => self::BALANCE_YER,
                'pending_balance' => 0,
            ],
        );
    }
}
