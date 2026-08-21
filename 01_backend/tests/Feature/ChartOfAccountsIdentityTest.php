<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerReportService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CHART-IDENTITY-001 — **`USER_WALLET_417` ليس هويّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * المحوران ٢٣ و٢٤ من وثيقة مركز الدفتر:
 *
 *     لا تجعل Chart of Accounts قائمةً مسطّحةً فقط — اعرض Hierarchy.
 *     ولا تعرض `USER_WALLET_1` وحدَه: أظهر الاسمَ والمالكَ والنوعَ
 *     والاتّجاهَ والعملةَ والحالة. **والرمزُ التقنيُّ ثانويّ.**
 *
 * **وثمنُ الرقم المجرَّد ليس راحةَ عين:** مراجعٌ يرى `owner_user_id: 417`
 * يفتح ملفَّ ذلك الرقم ليعرف صاحبَه — **فيُسجّل اطّلاعاً على بياناتٍ
 * شخصيّةٍ في كلّ مرّة**. والاسمُ هنا أقلُّ كشفاً وأكثرُ إفادة.
 */
class ChartOfAccountsIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function reports(): LedgerReportService
    {
        return app(LedgerReportService::class);
    }

    private function walletFor(string $first, int $type): User
    {
        $u = User::factory()->create(['f_name' => $first, 'l_name' => 'التجريبيّ', 'type' => $type]);
        app(LedgerService::class)->getOrCreateUserWallet($u->id);

        return $u;
    }

    /**
     * @test
     *
     * **① كلُّ حسابٍ يُسمّي مالكَه — لا يترك رقماً.**
     */
    public function every_wallet_account_names_its_owner(): void
    {
        $u = $this->walletFor('سالم', CUSTOMER_TYPE);

        $accounts = collect($this->reports()->chartOfAccounts()['groups'])
            ->flatMap(fn ($g) => collect($g['subgroups'])->flatMap(fn ($s) => $s['accounts']));

        $row = $accounts->firstWhere('account_code', 'USER_WALLET_'.$u->id);

        $this->assertNotNull($row, 'محفظةُ العميل غائبةٌ عن دليل الحسابات');
        $this->assertSame('سالم التجريبيّ', $row['owner_name'],
            '**رقمٌ بلا اسم**: مراجعٌ يفتح ملفَّ كلّ رقمٍ ليعرف صاحبَه، '
            . 'فيُسجّل اطّلاعاً على بياناتٍ شخصيّةٍ في كلّ مرّة');
        $this->assertSame('عميل', $row['owner_role']);
        $this->assertStringContainsString('سالم', $row['label'],
            'الاسمُ ليس أوّلاً في العنوان — والوثيقةُ تقول الرمزُ ثانويّ');
    }

    /**
     * @test
     *
     * **② والدليلُ مجموعٌ بفئاته الخمس لا مسطّح.**
     *
     * ══════════════════════════════════════════════════════════════════
     * أصولٌ وخصومٌ وإيراداتٌ متجاورةٌ بلا مجموعٍ لكلّ فئةٍ لا تُقرأ منها
     * ميزانيّة — وهي أوّلُ ما يطلبه مدقّقٌ أو منظّم.
     */
    public function the_chart_is_grouped_by_the_five_account_types(): void
    {
        $l = app(LedgerService::class);
        $l->getOrCreateSystemAccount('PLATFORM_FEE', 'revenue', 'رسوم', 'credit');
        $l->getOrCreateSystemAccount('CASH_OUT_HOLD', 'liability', 'حجز', 'credit');
        $l->getOrCreateSystemAccount('TREASURY_CASH_RESERVE', 'asset', 'خزينة', 'debit');

        $groups = collect($this->reports()->chartOfAccounts()['groups']);

        $this->assertGreaterThanOrEqual(3, $groups->count(),
            'الدليلُ ما زال مسطّحاً — لا فئاتٍ ولا مجاميع');

        foreach ($groups as $g) {
            $this->assertArrayHasKey('label', $g, 'فئةٌ بلا اسمٍ عربيّ');
            $this->assertArrayHasKey('total', $g, 'فئةٌ بلا مجموع');
            $this->assertNotEmpty($g['subgroups'], "فئة «{$g['label']}» بلا فئاتٍ فرعيّة");
        }

        $this->assertContains('الإيرادات', $groups->pluck('label')->all());
    }

    /**
     * @test
     *
     * **③ والفئةُ الفرعيّةُ تُستنتَج من الرمز — والجديدُ يظهر في «أخرى».**
     *
     * ══════════════════════════════════════════════════════════════════
     * فرمزٌ لا قاعدةَ له **لا يختفي**: يقع في «أخرى» ظاهراً فيُسأل عنه.
     * وتصنيفٌ يبتلع ما لا يعرفه يُخفي حساباً كاملاً عن الدليل.
     */
    public function an_unknown_code_lands_in_other_rather_than_vanishing(): void
    {
        app(LedgerService::class)->getOrCreateSystemAccount(
            'SOMETHING_BRAND_NEW', 'asset', 'رمزٌ لا قاعدةَ له', 'debit');

        $subs = collect($this->reports()->chartOfAccounts()['groups'])
            ->flatMap(fn ($g) => $g['subgroups']);

        $other = $subs->firstWhere('key', 'other');

        $this->assertNotNull($other, 'رمزٌ غيرُ مصنَّفٍ اختفى من الدليل بدل أن يظهر في «أخرى»');
        $this->assertContains('SOMETHING_BRAND_NEW',
            collect($other['accounts'])->pluck('account_code')->all());
    }

    /**
     * @test
     *
     * **④ والقطعُ يُقال — لا يُسكت عنه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * إنتاجٌ فيه آلافُ المحافظ يُخرج الحدَّ الأعلى **ولا يقول إنّه قطع**.
     * ومن قرأ «هذه حساباتُنا» على قائمةٍ مقطوعةٍ قرأ كذبة. (القاعدة ٧.)
     */
    public function a_truncated_chart_says_so(): void
    {
        $l = app(LedgerService::class);

        foreach (range(1, 6) as $i) {
            $l->getOrCreateSystemAccount('TRUNC_PROBE_'.$i, 'asset', 'حساب '.$i, 'debit');
        }

        $c = $this->reports()->chartOfAccounts(null, 3);

        $this->assertTrue($c['truncated'], '**قُطعت القائمةُ ولم تقل** — تُقرأ كاملةً وهي ليست');
        $this->assertSame(3, $c['shown']);
        $this->assertGreaterThan(3, $c['total']);
        $this->assertStringContainsString('مقطوعة', (string) $c['note']);

        // وقائمةٌ غيرُ مقطوعةٍ لا تدّعي القطع — وإلّا صار التحذيرُ ضجيجاً.
        $full = $this->reports()->chartOfAccounts(null, 500);
        $this->assertFalse($full['truncated']);
        $this->assertNull($full['note']);
    }

    /**
     * @test
     *
     * **⑤ ولا يُكسَر المستهلكُ القائم.**
     *
     * ══════════════════════════════════════════════════════════════════
     * تغييرُ شكل ردٍّ تقرؤه شاشةٌ **يُفرّغها بلا خطأٍ في أيّ سجلّ**:
     * `items` يصير `undefined`، والحلقةُ تدور صفرَ مرّة، والصفحةُ تردّ
     * ٢٠٠ فارغة. فيبقى `items` مسطوحاً بجانب الجديد.
     */
    public function the_flat_items_key_survives_for_existing_consumers(): void
    {
        $u = $this->walletFor('نورا', MERCHANT_TYPE);

        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin']);
        $roleId = \Illuminate\Support\Facades\DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', 'platform_admin')->value('id');

        if ($roleId) {
            \Illuminate\Support\Facades\DB::table('admin_user_roles')->insert([
                'user_id' => $admin->id, 'role_id' => $roleId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $meta = $this->actingAs($admin, 'user')
            ->getJson('/admin/amial/ledger/accounts')
            ->assertOk()->json('meta');

        $this->assertArrayHasKey('items', $meta,
            '**`items` اختفى** — الشاشةُ القديمةُ تدور صفرَ مرّةٍ وتردّ ٢٠٠ فارغة');
        $this->assertArrayHasKey('groups', $meta);
        $this->assertContains('USER_WALLET_'.$u->id, array_column($meta['items'], 'account_code'));
    }
}
