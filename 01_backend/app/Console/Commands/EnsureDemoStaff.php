<?php

namespace App\Console\Commands;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-DEMO-STAFF-001
 *
 * حسابات تجريبية للأدمن والوكيل (لتجربة لوحة الإدارة وشاشات الوكيل):
 *
 *   الأدمن  — تبويب «أدمن»: admin@amyalpay.com / Pass@2026  (بلا 2FA)
 *   الوكيل  — تبويب «وكيل»: رقم الوكيل AG-001 / الجوال 777900001 / Pass@2026
 *             رمز OTP التجريبي = قيمة AMIAL_DEMO_OTP (مثلاً 123456)
 *
 * idempotent — يعمل مع كل نشر بأمان.
 */
class EnsureDemoStaff extends Command
{
    protected $signature = 'amial:ensure-demo-staff';
    protected $description = 'تهيئة حسابي أدمن ووكيل تجريبيين';

    private const PASSWORD = 'Pass@2026';

    public function handle(): int
    {
        $this->ensureAdmin();
        $this->ensureAgent();
        return self::SUCCESS;
    }

    private function ensureAdmin(): void
    {
        try {
            $email = 'admin@amyalpay.com';
            $admin = User::where('type', ADMIN_TYPE)->where('email', $email)->first();
            if ($admin) {
                // AMIAL-WEB-ADMIN: لوحة الويب تدخل بالجوال — نُكمل الرقم إن كان
                // ناقصاً فقط (إضافة آمنة لا تمسّ شيئاً آخر).
                if (empty($admin->phone)) {
                    $admin->phone = '967777000001';
                    $admin->save();
                    $this->info('✓ أُضيف جوال للأدمن الموجود (967777000001) لدخول لوحة الويب');
                } else {
                    $this->info('✓ أدمن تجريبي موجود مسبقاً — لم يُمسّ');
                }
                return;
            }
            $admin = new User();

            $admin->f_name = 'مدير';
            $admin->l_name = 'النظام';
            $admin->email = $email;
            // AMIAL-WEB-ADMIN: لوحة الويب (admin/auth/login) تدخل بالجوال
            $admin->phone = '967777000001';
            $admin->type = ADMIN_TYPE; // 0
            $admin->password = Hash::make(self::PASSWORD);
            $admin->is_active = 1;
            // بلا مصادقة ثنائية ليسهل الدخول التجريبي
            if (Schema::hasColumn('users', 'two_factor_enabled')) {
                $admin->two_factor_enabled = 0;
            }
            if (Schema::hasColumn('users', 'zone_code')) {
                $admin->zone_code = 'SOUTH';
            }
            $admin->save();

            $this->info("✓ أدمن تجريبي جاهز — {$email} / " . self::PASSWORD);
        } catch (\Throwable $e) {
            $this->error('❌ فشل إنشاء الأدمن: ' . $e->getMessage());
        }
    }

    private function ensureAgent(): void
    {
        try {
            $agentNumber = 'AG-001';
            $phone = '967777900001';

            $agent = User::where('type', AGENT_TYPE)->get()->first(function ($u) use ($agentNumber, $phone) {
                return $u->agent_number === $agentNumber
                    || in_array($u->phone, [$phone, '777900001'], true);
            });
            if ($agent) {
                EMoney::firstOrCreate([chr(39)."user_id".chr(39) => $agent->id], [chr(39)."current_balance".chr(39) => chr(39)."500000.0000".chr(39), chr(39)."charge_earned".chr(39) => chr(39)."0".chr(39), chr(39)."pending_balance".chr(39) => chr(39)."0".chr(39), chr(39)."held_balance".chr(39) => chr(39)."0".chr(39), chr(39)."zone_code".chr(39) => chr(39)."SOUTH".chr(39), chr(39)."version".chr(39) => 1]);
                $this->info("✓ وكيل تجريبي موجود مسبقاً — لم يُمسّ");
                return;
            }
            $agent = new User();

            $agent->f_name = 'خالد';
            $agent->l_name = 'الوكيل';
            $agent->phone = $phone;
            $agent->type = AGENT_TYPE; // 1
            $agent->password = Hash::make(self::PASSWORD);
            $agent->is_active = 1;
            if (Schema::hasColumn('users', 'agent_number')) {
                $agent->agent_number = $agentNumber;
            }
            if (Schema::hasColumn('users', 'transaction_pin')) {
                $agent->transaction_pin = Hash::make('1237');
            }
            if (Schema::hasColumn('users', 'is_kyc_verified')) {
                $agent->is_kyc_verified = 1;
            }
            if (Schema::hasColumn('users', 'zone_code')) {
                $agent->zone_code = 'SOUTH';
            }
            if (Schema::hasColumn('users', 'kyc_tier')) {
                $agent->kyc_tier = 3;
            }
            $agent->save();

            // محفظة الوكيل (سيولة لصرف عمليات السحب للعملاء)
            EMoney::firstOrCreate(
                ['user_id' => $agent->id],
                [
                    'current_balance' => '500000.0000',
                    'charge_earned' => '0', 'pending_balance' => '0',
                    'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 1,
                ]
            );

            $otpHint = config('app.amial_demo_otp') ?: '(اضبط AMIAL_DEMO_OTP)';
            $this->info("✓ وكيل تجريبي جاهز — {$agentNumber} / {$phone} / " . self::PASSWORD . " — OTP: {$otpHint}");
        } catch (\Throwable $e) {
            $this->error('❌ فشل إنشاء الوكيل: ' . $e->getMessage());
        }
    }
}
