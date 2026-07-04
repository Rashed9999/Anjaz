<?php

namespace Database\Seeders;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * بيانات تجريبية للعرض على المستثمر.
 *
 * الحسابات المُنشأة:
 *   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 *   مدير النظام (أدمن):
 *     البريد:    admin@amyal.pay
 *     كلمة السر: Admin@2026
 *
 *   عميل تجريبي 1:
 *     الهاتف:   777100001
 *     PIN:      1234
 *     الرصيد:   50,000 ريال
 *
 *   عميل تجريبي 2:
 *     الهاتف:   777100002
 *     PIN:      1234
 *     الرصيد:   25,000 ريال
 *
 *   تاجر تجريبي:
 *     الهاتف:   777200001
 *     PIN:      1234
 *     الرصيد:   200,000 ريال
 *
 *   وكيل تجريبي:
 *     الهاتف:   777300001
 *     PIN:      1234
 *     الرصيد:   500,000 ريال
 *   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── أدمن ─────────────────────────────────────────
        $this->createAdmin();

        // ── عملاء ────────────────────────────────────────
        $customer1 = $this->createUser([
            'f_name' => 'أحمد',      'l_name' => 'سالم',
            'phone'  => '967777100001', 'type'  => 2,  // عميل
            'zone_code' => 'SOUTH',
        ], 50000);

        $customer2 = $this->createUser([
            'f_name' => 'فاطمة',    'l_name' => 'علي',
            'phone'  => '967777100002', 'type' => 2,  // عميل
            'zone_code' => 'SOUTH',
        ], 25000);

        // ── تاجر ────────────────────────────────────────
        $merchant = $this->createUser([
            'f_name' => 'محمّد',    'l_name' => 'الحضرمي',
            'phone'  => '967777200001', 'type' => 3,  // تاجر
            'zone_code' => 'SOUTH',
        ], 200000);

        // ── وكيل ────────────────────────────────────────
        $agent = $this->createUser([
            'f_name' => 'عبدالله', 'l_name' => 'العمقي',
            'phone'  => '967777300001', 'type' => 1, 'role' => 'agent',  // وكيل
            'zone_code' => 'SOUTH',
        ], 500000);

        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════╗');
        $this->command->info('║     أميال باي — بيانات العرض جاهزة               ║');
        $this->command->info('╠══════════════════════════════════════════════════╣');
        $this->command->info('║  أدمن:    admin@amyal.pay  / Admin@2026           ║');
        $this->command->info('║  عميل 1:  777100001       / PIN: 1234             ║');
        $this->command->info('║  عميل 2:  777100002       / PIN: 1234             ║');
        $this->command->info('║  تاجر:    777200001       / PIN: 1234             ║');
        $this->command->info('║  وكيل:    777300001       / PIN: 1234             ║');
        $this->command->info('╚══════════════════════════════════════════════════╝');
    }

    private function createAdmin(): User
    {
        return User::updateOrCreate(
            ['email' => 'admin@amyal.pay'],
            [
                'f_name'   => 'مدير',
                'l_name'   => 'النظام',
                'phone'    => '967700000000',
                'password' => Hash::make('Admin@2026'),
                'type'     => 0,            // AMIAL-FIX: ADMIN_TYPE=0 (كان 1 = وكيل)
                'role'     => 'super_admin',
                'is_active'=> 1,
            ]
        );
    }

    private function createUser(array $data, int $balance): User
    {
        $user = User::updateOrCreate(
            ['phone' => $data['phone']],
            array_merge($data, [
                'password'         => Hash::make('Pass@2026'),
                'transaction_pin'  => '1234',   // cast 'hashed' يُطبّق Hash تلقائياً
                'is_active'        => 1,
            ])
        );

        EMoney::updateOrCreate(
            ['user_id' => $user->id],
            [
                'current_balance' => $balance,
                'pending_balance' => 0,
            ]
        );

        return $user;
    }
}
