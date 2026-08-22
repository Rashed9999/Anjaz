<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-TXN-INDEX-500-001 — كشف العمليات لا ينهار على قيود النظام.
 *
 * العملية المحاسبية أو الرسم قد لا يملكان مرسلاً أو مستلماً. كان القالب
 * يمرر NULL إلى `Helpers::get_user_info(int $user_id)` فيسقط كشف المعاملات
 * كاملاً بـ500. هذا العقد يفتح الصفحة بالسجل الذي كان يكسرها فعلاً.
 */
class AdminTransactionIndexTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = new User();
        $admin->forceFill([
            'f_name' => 'مدير', 'l_name' => 'المنصة', 'phone' => '967770009901',
            'email' => 'transaction-index@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        // AMIAL-ADMIN-DOORS-001 — كشفُ المعاملات صار خلف `transactions.view`.
        // ونوعُ الحساب لم يعد يفتحه: «أهو موظّف؟» ليس «هل يحقّ له؟».
        app(\App\Services\PlatformRoleService::class)
            ->assign($admin, \App\Services\PlatformRoleService::ADMIN);

        return $admin->refresh();
    }

    /** @test */
    public function system_rows_without_transfer_parties_do_not_break_the_transaction_statement(): void
    {
        $admin = $this->admin();

        DB::table('transactions')->insert([
            'user_id' => $admin->id,
            'ref_trans_id' => null,
            'transaction_id' => 'SYS-INDEX-000001',
            'transaction_type' => 'platform_fee',
            'debit' => 0,
            'credit' => 0,
            'charge' => 15,
            'amount' => 15,
            'balance' => 0,
            'from_user_id' => null,
            'to_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'user')
            ->get(route('admin.transaction.index'))
            ->assertOk()
            ->assertSee('SYS-INDEX-000001')
            ->assertSee('—');
    }
}
