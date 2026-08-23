<?php

namespace App\Console\Commands;

use App\Support\DemoAccountPolicy;
use App\Models\EMoney;
use App\Models\User;
use App\Services\UnifiedAuthService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * AMIAL-DEMO-001 — يضمن وجود حساب عميل تجريبي صحيح ويختبر الدخول فعلياً.
 *
 * يحلّ فشل الدخول نهائياً: يُنشئ العميل بالمفاتيح الحالية، ثم يستدعي مسار
 * الدخول الفعلي ويطبع النتيجة القاطعة في السجلّ (تظهر في Railway).
 *
 *   php artisan amial:ensure-demo
 */
class EnsureDemoUsers extends Command
{
    protected $signature = 'amial:ensure-demo';
    protected $description = 'يضمن حساب عميل تجريبي ويختبر الدخول';

    public function handle(): int
    {
        $phone = '967777100001';
        $password = 'Pass@2026';

        // 1) إنشاء/تحديث العميل (الموديل يملأ المشفّر + البصمة بالمفاتيح الحالية)
        try {
            $user = User::where('type', 2)->get()->first(function ($u) use ($phone) {
                return in_array($u->phone, [$phone, '777100001'], true)
                    || $u->phone_blind_index === app(\App\Services\EncryptionService::class)->blindIndex($phone, 'phone');
            });

            // AMIAL-REAL-DATA: حساب موجود لا يُعاد ضبطه (سر/رمز/رصيد) —
            // النشرات كانت «تمسح» النشاط وتُبطل الجلسات.
            if ($user) {
                // AMIAL-DEMO-FIX: حسابات التجربة يجب أن تدخل دائماً بكلمة المرور
                // المعروفة. سابقاً «لم يُمسّ» → إن كان السرّ قديماً/مختلفاً فشل
                // الدخول بـ«بيانات غير صحيحة» للأبد. نفرض بيانات الدخول فقط
                // (كلمة المرور/الرمز/التفعيل/التوثيق) دون المساس بالرصيد والنشاط.
                // AMIAL-DEMO-CREDENTIALS-001 — الإنشاءُ يبقى، وإعادةُ الضبط تتوقّف
                // في الإنتاج: هي التي تمحو تغييراً متعمَّداً في كلّ نشرة، بصمت.
                if (DemoAccountPolicy::mayResetExisting()) {
                    $user->password = Hash::make($password);
                } else {
                    $this->warn(DemoAccountPolicy::skipNotice('عميل'));
                }
                $user->transaction_pin = Hash::make('1237');
                $user->is_active = 1;
                $user->is_kyc_verified = 1;
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_tier')) {
                    $user->kyc_tier = 3;
                }
                $user->save();
                EMoney::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'current_balance' => '50000.0000',
                        'charge_earned' => '0', 'pending_balance' => '0',
                        'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 1,
                    ]
                );
                $this->info("✓ حساب العميل التجريبي موجود — فُرضت بيانات الدخول id={$user->id}");
                goto demo_recipient;
            }
            $user = new User();
            $user->f_name = 'أحمد';
            $user->l_name = 'سالم';
            $user->phone = $phone;
            $user->type = 2;
            $user->password = Hash::make(DemoAccountPolicy::passwordForNewAccount(
                $password, 'AMIAL_BOOTSTRAP_CUSTOMER_PASSWORD'));
            $user->transaction_pin = Hash::make('1237');
            $user->is_active = 1;
            // AMIAL-DEMO: موثّق KYC (=1) ليعمل إرسال الأموال (يشترط التوثيق)
            $user->is_kyc_verified = 1;
            $user->zone_code = 'SOUTH';
            // AMIAL-TRANSFER-V2: مسار التحويل الجديد يفرض مستويات KYC — «كامل»
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_tier')) {
                $user->kyc_tier = 3;
            }
            $user->save();

            EMoney::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'current_balance' => '50000.0000',
                    'charge_earned' => '0', 'pending_balance' => '0',
                    'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 1,
                ]
            );

            $this->info("✓ حساب العميل التجريبي جاهز id={$user->id} phone={$phone}");
        } catch (\Throwable $e) {
            $this->error('❌ فشل إنشاء العميل التجريبي: ' . $e->getMessage());
            // لا نُرجِع FAILURE — نُكمل باقي التهيئة (العملة/الميزات) بأمان.
        }

        // AMIAL-FIX(RESILIENCE): كل قسم في try مستقلّ — فشل أحدها لا يُسقط الباقي
        // (كان كلّ شيء في try واحد؛ فشل المستلِم يُلغي ضبط العملة والميزات).

        // 1b) مستلِم تجريبي (لتجربة إرسال الأموال) — 967777100002
        demo_recipient:
        try {
            $rxPhone = '967777100002';
            $existingRx = User::where('type', 2)->get()->first(function ($u) use ($rxPhone) {
                return in_array($u->phone, [$rxPhone, '777100002'], true);
            });
            if ($existingRx) {
                // AMIAL-DEMO-FIX: فرض بيانات الدخول لحساب المستلِم التجريبي أيضاً.
                // AMIAL-DEMO-CREDENTIALS-001 — الإنشاءُ يبقى، وإعادةُ الضبط تتوقّف
                // في الإنتاج: هي التي تمحو تغييراً متعمَّداً في كلّ نشرة، بصمت.
                if (DemoAccountPolicy::mayResetExisting()) {
                    $existingRx->password = Hash::make($password);
                } else {
                    $this->warn(DemoAccountPolicy::skipNotice('مستلِم'));
                }
                $existingRx->transaction_pin = Hash::make('1237');
                $existingRx->is_active = 1;
                $existingRx->is_kyc_verified = 1;
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_tier')) {
                    $existingRx->kyc_tier = 3;
                }
                $existingRx->save();
                EMoney::firstOrCreate(
                    ['user_id' => $existingRx->id],
                    [
                        'current_balance' => '10000.0000',
                        'charge_earned' => '0', 'pending_balance' => '0',
                        'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 1,
                    ]
                );
                $this->info("✓ المستلِم التجريبي موجود — فُرضت بيانات الدخول id={$existingRx->id}");
                goto after_recipient;
            }
            $recipient = new User();
            $recipient->f_name = 'محمد';
            $recipient->l_name = 'علي';
            $recipient->phone = $rxPhone;
            $recipient->type = 2;
            $recipient->password = Hash::make(DemoAccountPolicy::passwordForNewAccount(
                $password, 'AMIAL_BOOTSTRAP_CUSTOMER_PASSWORD'));
            $recipient->transaction_pin = Hash::make('1237');
            $recipient->is_active = 1;
            $recipient->is_kyc_verified = 1;
            $recipient->zone_code = 'SOUTH';
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_tier')) {
                $recipient->kyc_tier = 3;
            }
            $recipient->save();
            EMoney::firstOrCreate(
                ['user_id' => $recipient->id],
                [
                    'current_balance' => '10000.0000',
                    'charge_earned' => '0', 'pending_balance' => '0',
                    'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 1,
                ]
            );
            $this->info("✓ المستلِم التجريبي جاهز id={$recipient->id} phone={$rxPhone} (محمد علي)");
        } catch (\Throwable $e) {
            $this->error('❌ فشل إنشاء المستلِم: ' . $e->getMessage());
        }
        after_recipient:

        // 1c) تفعيل مفاتيح الميزات (بدونها كل الخدمات «feature is not activate»)
        try {
            $featureKeys = [
                'send_money_status', 'cash_out_status', 'add_money_status',
                'withdraw_request_status', 'send_money_request_status',
                'favorite_number_status', 'banner_status', 'faq_section_status',
                'linked_website_status', 'report_disputes_status',
            ];
            foreach ($featureKeys as $k) {
                \Illuminate\Support\Facades\DB::table('business_settings')
                    ->updateOrInsert(['key' => $k], ['value' => '1']);
            }
            // AMIAL-PILOT: تعطيل اشتراط OTP في التسجيل (لا SMS مُهيّأ بعد) — فعّله
            // لاحقاً بعد ضبط مزوّد الرسائل. المعالج يعرض خطوة الرمز دائماً.
            \Illuminate\Support\Facades\DB::table('business_settings')
                ->updateOrInsert(['key' => 'phone_verification'], ['value' => '0']);
            // AMIAL-PILOT: إطالة مهلة الخمول (كانت 20 دقيقة → تُخرج المستخدم أثناء
            // التجربة برسالة «Token Expired»). 7 أيام للتجربة؛ اضبطها لاحقاً للإنتاج.
            \Illuminate\Support\Facades\DB::table('business_settings')
                ->updateOrInsert(['key' => 'inactive_auth_minute'], ['value' => '10080']);
            $this->info('✓ مفاتيح الميزات مُفعّلة (' . count($featureKeys) . ' مفتاح)');
        } catch (\Throwable $e) {
            $this->error('❌ فشل تفعيل الميزات: ' . $e->getMessage());
        }

        // 1c-2) كتالوجات الخدمات — الـ seeders مشروطة بـ RUN_SEEDERS في entrypoint
        // فلم تعمل غالباً → «الفواتير» و«التبرعات» تفتح فارغة. كلّها idempotent
        // (updateOrCreate) فنشغّلها هنا كل إقلاع بأمان.
        foreach ([
            \Database\Seeders\BillProvidersStubSeeder::class,
            \Database\Seeders\CharityCategoriesSeeder::class,
            \Database\Seeders\FeeSchemeSeeder::class,
            \Database\Seeders\FeatureFlagsSeeder::class,
            \Database\Seeders\KycTierLimitsSeeder::class,
        ] as $seeder) {
            try {
                // عبر db:seed ليتوفّر سياق command (بعض الـ seeders تستدعي info())
                \Illuminate\Support\Facades\Artisan::call('db:seed', [
                    '--class' => $seeder, '--force' => true,
                ]);
                $this->info('✓ seeder: ' . class_basename($seeder));
            } catch (\Throwable $e) {
                $this->error('❌ seeder ' . class_basename($seeder) . ': ' . $e->getMessage());
            }
        }

        // 1d) العملة ريال يمني فقط (كانت ر.س/SAR على الشاشات)
        try {
            \Illuminate\Support\Facades\DB::table('business_settings')
                ->updateOrInsert(['key' => 'currency'], ['value' => 'YER']);
            \Illuminate\Support\Facades\DB::table('business_settings')
                ->updateOrInsert(['key' => 'currency_symbol_position'], ['value' => 'right']);
            \Illuminate\Support\Facades\DB::table('currencies')->updateOrInsert(
                ['currency_code' => 'YER'],
                ['country' => 'Yemen', 'currency_symbol' => 'ر.ي']
            );
            $this->info('✓ العملة مضبوطة: ريال يمني (YER / ر.ي)');
        } catch (\Throwable $e) {
            $this->error('❌ فشل ضبط العملة: ' . $e->getMessage());
        }

        // AMIAL-DEMO-MERCHANTS-001: تجّار تجريبيون لكل قطاع (يبقى مستقلاً بفشله)
        try {
            \Illuminate\Support\Facades\Artisan::call('amial:ensure-demo-merchants');
            $this->line(trim(\Illuminate\Support\Facades\Artisan::output()));
        } catch (\Throwable $e) {
            $this->error('❌ فشل تهيئة تجّار التجربة: ' . $e->getMessage());
        }

        // AMIAL-DEMO-STAFF-001: أدمن ووكيل تجريبيان (لتجربة لوحة الإدارة والوكيل)
        try {
            \Illuminate\Support\Facades\Artisan::call('amial:ensure-demo-staff');
            $this->line(trim(\Illuminate\Support\Facades\Artisan::output()));
        } catch (\Throwable $e) {
            $this->error('❌ فشل تهيئة أدمن/وكيل التجربة: ' . $e->getMessage());
        }

        // 2) اختبار الدخول الفعلي عبر نفس مسار التطبيق
        try {
            $svc = app(UnifiedAuthService::class);
            $req = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'Dart/3.0 (dart:io)',
            ]);
            // نجرّب بالصيغة التي يدخلها المستخدم (بلا 967)
            $result = $svc->loginCustomer('777100001', $password, $req);
            $this->info('✅✅✅ اختبار الدخول نجح — التطبيق سيدخل بـ 777100001 / '
                . DemoAccountPolicy::describe($password, 'AMIAL_BOOTSTRAP_CUSTOMER_PASSWORD'));
            $this->info('    token صدر: ' . (isset($result['token']) ? 'نعم' : 'لا'));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌❌❌ اختبار الدخول فشل: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
