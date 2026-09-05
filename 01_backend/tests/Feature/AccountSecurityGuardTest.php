<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransactionPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-ACCOUNT-SECURITY-001 — **رمزُ التحويل هو كلمةُ المرور، ولا بابَ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قال صاحبُ المشروع: «آخرُ خطوةٍ في التسجيل هي إدخالُ رمز PIN وكلمة
 * المرور، **ويبدو أنّ رمز PIN هو كلمةُ المرور** — إذن كيف يمكن تغييرُ
 * كلمة المرور بعد تسجيل الدخول؟ **لا يوجد طريقة**».
 *
 * وقِيس، فكانتا اثنتين لا واحدة:
 *
 *   ① `RegisterController:204` → `$user->transaction_pin = $request->password`
 *      والحقلُ `'hashed'` في `$casts`. **فالسرّان واحد.**
 *   ② `grep -rn "change-password" routes/` → **لا شيء**. والموجودُ
 *      `forgot-password` وحدَه — أي أنّ من يريد تغييرَ كلمته يجب أن
 *      «ينساها» أوّلاً.
 *
 * **ولمَ لم يُحذَف السطرُ ①.** `FALLBACK_DEADLINE = 2026-06-15` قد مضى،
 * فحسابٌ بلا `transaction_pin` يُرفَض دائماً. حذفُ السطر يمنع كلَّ مسجَّلٍ
 * جديدٍ من التحويل — **إصلاحُ ثقبٍ بقفل الباب**. فيُبقى الرمزُ ويُفصَل.
 */
class AccountSecurityGuardTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/amial/me/security';

    private function account(string $password = 'aa1234', int $type = CUSTOMER_TYPE): User
    {
        return User::factory()->create([
            'type' => $type, 'is_active' => 1,
            'password' => Hash::make($password),
        ]);
    }

    /** حسابٌ كما يخرج من التسجيل: الرمزُ هو كلمةُ المرور، ولا تاريخَ اختيار. */
    private function asRegistered(User $u, string $password): User
    {
        $u->transaction_pin = $password;      // `'hashed'` — تُعمّى تلقائياً
        $u->transaction_pin_set_at = null;
        $u->save();

        return $u->fresh();
    }

    /** @test */
    public function the_screen_says_plainly_that_the_pin_is_the_password(): void
    {
        // **والصمتُ هو المرفوض** — من لا يعرف أنّ رمزَه هو كلمةُ مروره لا
        // يبحث عن تغييره أصلاً. (القاعدة السابعة.)
        $u = $this->asRegistered($this->account('aa1234'), 'aa1234');

        $r = $this->actingAs($u, 'api')->getJson(self::BASE);

        $r->assertOk();
        $this->assertTrue($r->json('meta.pin_equals_password'),
            'لا يُقال لصاحب الحساب إنّ رمزَ تحويله هو كلمةُ مروره نفسُها');
        $this->assertFalse($r->json('meta.pin_was_chosen'));
        $this->assertNotEmpty($r->json('meta.notice'));
    }

    /** @test */
    public function a_door_exists_to_change_the_password_after_login(): void
    {
        // **البابُ الذي لم يكن موجوداً إطلاقاً.**
        $u = $this->account('aa1234');

        $r = $this->actingAs($u, 'api')->postJson(self::BASE.'/password', [
            'current_password' => 'aa1234',
            'new_password' => 'zz9876',
            'new_password_confirmation' => 'zz9876',
        ]);

        $r->assertOk();
        $this->assertTrue(Hash::check('zz9876', $u->fresh()->password),
            'مرّ الطلبُ ولم تتغيّر كلمةُ المرور فعلاً في القاعدة');
    }

    /** @test */
    public function the_old_password_is_required_and_a_wrong_one_changes_nothing(): void
    {
        $u = $this->account('aa1234');

        $this->actingAs($u, 'api')->postJson(self::BASE.'/password', [
            'current_password' => 'خطأ',
            'new_password' => 'zz9876',
            'new_password_confirmation' => 'zz9876',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('aa1234', $u->fresh()->password),
            'غُيّرت كلمةُ المرور بلا معرفة القديمة — فمن أمسك هاتفاً مفتوحاً '
            .'أخذ الحساب');
    }

    /** @test */
    public function changing_the_password_never_moves_the_pin_with_it(): void
    {
        // **ولو تبعها الرمزُ لعاد العطلُ نفسُه بثوبٍ جديد**: يظنّ صاحبُه
        // أنّه فصلهما وهما واحد.
        $u = $this->asRegistered($this->account('aa1234'), 'aa1234');

        $r = $this->actingAs($u, 'api')->postJson(self::BASE.'/password', [
            'current_password' => 'aa1234',
            'new_password' => 'zz9876',
            'new_password_confirmation' => 'zz9876',
        ]);

        $r->assertOk();
        $this->assertTrue($r->json('meta.pin_equals_password'),
            'لم يُقَل لصاحبه إنّ رمزَه ما زال كلمةَ مروره **القديمة** — '
            .'فيظنّ البابَ أُغلق وقد بقي مفتوحاً بها');

        $fresh = $u->fresh();
        $this->assertTrue(Hash::check('aa1234', $fresh->transaction_pin),
            'تبع الرمزُ كلمةَ المرور فبقيا واحداً');
    }

    /** @test */
    public function the_merchant_can_choose_a_pin_too_not_only_the_customer(): void
    {
        // **والتاجرُ كان أبعدَهم عن الباب**: `change-pin` مبنيٌّ للعميل
        // والوكيل وحدَهما، وهو من يحرّك أكبرَ المبالغ.
        $u = $this->asRegistered($this->account('aa1234', MERCHANT_TYPE), 'aa1234');

        $this->actingAs($u, 'api')->postJson(self::BASE.'/pin', [
            'current' => 'aa1234',
            'new_pin' => '4821',
            'new_pin_confirmation' => '4821',
        ])->assertOk();

        $fresh = $u->fresh();
        $this->assertTrue(Hash::check('4821', $fresh->transaction_pin));
        $this->assertNotNull($fresh->transaction_pin_set_at,
            'اختيرَ الرمزُ ولم يُختَم بتاريخٍ — وبه وحدَه يُعرَف المختارُ '
            .'من رمز التسجيل');
        $this->assertFalse(Hash::check('4821', $fresh->password),
            'صار الرمزُ كلمةَ المرور — وهو العطلُ نفسُه معكوساً');
    }

    /** @test */
    public function a_pin_equal_to_the_password_is_refused_with_its_reason(): void
    {
        $u = $this->asRegistered($this->account('4821'), '4821');

        $r = $this->actingAs($u, 'api')->postJson(self::BASE.'/pin', [
            'current' => '4821',
            'new_pin' => '4821',
            'new_pin_confirmation' => '4821',
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('كلمةَ مرورك', (string) $r->json('message'),
            'رُفض ولم يُقَل سببُه — ورفضٌ صامتٌ يُرسل صاحبَه يجرّب');
    }

    /** @test */
    public function the_registration_pin_still_works_because_the_fallback_is_closed(): void
    {
        // **الحارسُ الذي يمنع «الإصلاحَ» الخاطئ.** حذفُ سطر التسجيل يترك
        // الحسابَ بلا `transaction_pin`، و`FALLBACK_DEADLINE` قد مضى —
        // فيُرفَض كلُّ تحويلٍ إلى الأبد. يُقاس ولا يُفترَض:
        $this->assertTrue(
            now()->gt(\Carbon\Carbon::parse(TransactionPinService::FALLBACK_DEADLINE)),
            'مهلةُ الرجوع إلى كلمة المرور لم تنقضِ بعد — فيُراجَع هذا الحارس');

        $withPin = $this->asRegistered($this->account('aa1234'), 'aa1234');
        $this->assertTrue(app(TransactionPinService::class)->verify($withPin, 'aa1234'),
            'رمزُ التسجيل لا يعمل — والحسابُ لا يحوّل ريالاً');

        $withoutPin = $this->account('aa1234');
        $withoutPin->transaction_pin = null;
        $withoutPin->save();

        $this->assertFalse(
            app(TransactionPinService::class)->verify($withoutPin->fresh(), 'aa1234'),
            'حسابٌ بلا رمزٍ يمرّ بكلمة المرور — والمهلةُ منقضية، '
            .'فإمّا أنّ الحدَّ مُدّد أو أنّ الفحصَ لا يُطبَّق');
    }

    /** @test */
    public function the_pin_route_is_shut_to_a_guest(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
        $this->postJson(self::BASE.'/password', [])->assertStatus(401);
        $this->postJson(self::BASE.'/pin', [])->assertStatus(401);
    }
}
