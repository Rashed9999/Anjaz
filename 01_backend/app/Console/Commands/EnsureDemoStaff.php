<?php

namespace App\Console\Commands;

use App\Models\EMoney;
use App\Models\User;
use App\Services\PlatformRoleService;
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
        $this->healOrphanAdmins();
        return self::SUCCESS;
    }

    /**
     * AMIAL-OPERATOR-RBAC-004 — شبكةُ الأمان تُشغَّل في كلّ إقلاع فعلاً.
     *
     * **وكانت مكتوبةً «تُشغَّل في كلّ إقلاع» وهي داخل فرعٍ واحد.**
     *
     * جلست داخل `if ($admin)` — أي مسارِ «الأدمن التجريبيّ موجودٌ سلفاً»
     * وحده. فعلى الإقلاع الذي يُبذَر فيه غيرُه من حسابات الإدارة — وهو
     * أوّلُ إقلاعٍ على قاعدةٍ جديدة، وأكثرُ الأوقات إنتاجاً لحساباتٍ بلا
     * دور — يُؤخذ الفرعُ الآخر ولا تعمل الشبكة إطلاقاً.
     *
     * والقياس: `admin@amyal.pay` من `DemoDataSeeder` بقي **بلا دور** بعد
     * تشغيل الأمر كاملاً — يدخل اللوحة ويُردّ ٤٠٣ على واحدٍ وأربعين
     * مساراً، ولا يقرأ رقماً ماليّاً واحداً.
     *
     * فصارت خطوةً مستقلّةً في `handle()`: لا تتبع وجودَ حسابٍ بعينه ولا
     * نجاحَ خطوةٍ قبلها.
     */
    private function healOrphanAdmins(): void
    {
        try {
            $healed = app(PlatformRoleService::class)->healAdminsWithoutRoles();

            if ($healed > 0) {
                $this->info("✓ أُسند دور مدير المنصّة لـ{$healed} حساب إدارةٍ كان بلا دور");
            }
        } catch (\Throwable $e) {
            $this->error('❌ تعذّر شفاء حسابات الإدارة بلا دور: ' . $e->getMessage());
        }
    }

    private function ensureAdmin(): void
    {
        try {
            $email = 'admin@amyalpay.com';
            $admin = User::where('type', ADMIN_TYPE)->where('email', $email)->first();
            if ($admin) {
                // AMIAL-FIX(ADMIN-LOGIN): «ضمان» يعني بيانات دخول معلومة دوماً.
                // كان يُبقي كلمة سرّ قديمة/مجهولة فيفشل الدخول بـ«Credentials
                // does not match» رغم اتباع الدليل. نعيد ضبط جوال/كلمة سرّ
                // الأدمن التجريبي هذا فقط (بإيميله المعروف) — لا يُمسّ غيره.
                if (empty($admin->phone)) {
                    $admin->phone = '967777000001';
                }
                $admin->password = Hash::make(self::PASSWORD);
                $admin->is_active = 1;
                if (Schema::hasColumn('users', 'two_factor_enabled')) {
                    $admin->two_factor_enabled = 0;
                }
                $admin->save();
                // AMIAL-OPERATOR-RBAC-002: حسابٌ قائمٌ قد يكون أُنشئ بعد هجرة
                // الأدوار فلا دورَ له — فيرى ٤٠٣ على ٤١ مساراً بلا تفسير.
                app(PlatformRoleService::class)->ensureHasSomeRole($admin);

                // وشبكةُ الأمان لكلّ حسابات الإدارة انتقلت إلى
                // `healOrphanAdmins()` في `handle()` — فهي لا تخصّ هذا
                // الفرع، وبقاؤها هنا كان يُعطّلها في المسار الآخر.
                $this->info("✓ أدمن تجريبي مضمون — جوال {$admin->phone} / " . self::PASSWORD);
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

            // **الإسناد جزءٌ من الإنشاء لا خطوةٌ بعده.**
            // فحسابُ إدارةٍ بلا دورٍ يُقفل عليه ٤١ مساراً بلا رسالة.
            app(PlatformRoleService::class)->ensureHasSomeRole($admin);

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
                // AMIAL-DEMO-FIX: نفرض بيانات الدخول للوكيل التجريبي (سرّ/رمز/
                // تفعيل/دور) دون المساس بالرصيد — وإلا فشل الدخول بسرّ قديم.
                $agent->password = Hash::make(self::PASSWORD);
                $agent->is_active = 1;
                if (Schema::hasColumn('users', 'transaction_pin')) {
                    $agent->transaction_pin = Hash::make('1237');
                }
                if (Schema::hasColumn('users', 'role') && $agent->role !== 'agent') {
                    $agent->role = 'agent';
                }
                if (Schema::hasColumn('users', 'agent_number') && empty($agent->agent_number)) {
                    $agent->agent_number = $agentNumber;
                }
                $agent->save();
                EMoney::firstOrCreate(
                    ['user_id' => $agent->id],
                    [
                        'current_balance' => '500000.0000',
                        'charge_earned' => '0',
                        'pending_balance' => '0',
                        'held_balance' => '0',
                        'zone_code' => 'SOUTH',
                        'version' => 1,
                    ]
                );
                $this->info("✓ وكيل تجريبي موجود — فُرضت بيانات الدخول");
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

            $otpHint = config('amial.otp.demo_code') ?: '(اضبط AMIAL_DEMO_OTP)';
            $this->info("✓ وكيل تجريبي جاهز — {$agentNumber} / {$phone} / " . self::PASSWORD . " — OTP: {$otpHint}");
        } catch (\Throwable $e) {
            $this->error('❌ فشل إنشاء الوكيل: ' . $e->getMessage());
        }
    }
}
