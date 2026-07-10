<?php

namespace App\Console\Commands;

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

            if (!$user) {
                $user = new User();
            }
            $user->f_name = 'أحمد';
            $user->l_name = 'سالم';
            $user->phone = $phone;
            $user->type = 2;
            $user->password = Hash::make($password);
            $user->transaction_pin = '1234';
            $user->is_active = 1;
            $user->zone_code = 'SOUTH';
            $user->save();

            EMoney::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'current_balance' => '50000.0000',
                    'charge_earned' => '0', 'pending_balance' => '0',
                    'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 1,
                ]
            );

            $this->info("✓ حساب العميل التجريبي جاهز id={$user->id} phone={$phone}");
        } catch (\Throwable $e) {
            $this->error('❌❌❌ فشل إنشاء العميل التجريبي: ' . $e->getMessage());
            return self::FAILURE;
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
            $this->info('✅✅✅ اختبار الدخول نجح — التطبيق سيدخل بـ 777100001 / ' . $password);
            $this->info('    token صدر: ' . (isset($result['token']) ? 'نعم' : 'لا'));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌❌❌ اختبار الدخول فشل: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
