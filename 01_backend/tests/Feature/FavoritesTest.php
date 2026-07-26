<?php

namespace Tests\Feature;

use App\Models\FavouriteNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-FAVORITES-001 — مفضّلة موحّدة.
 *
 * الجدول كان للأرقام وحدها، وهو نفسه الذي يُغذّي خصم رسوم التحويل والسحب.
 * التوسيع أضاف أنواعاً بلا هاتف (عملية، حساب) — فالخطر الأول أن تتسرّب
 * هذه القيم إلى قائمة مطابقة الخصم فتفسده. تحرسه هذه المجموعة صراحةً.
 */
class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(['type' => 2]);
    }

    private function toggle(User $u, array $payload)
    {
        return $this->actingAs($u, 'api')->postJson('/api/v1/amial/favorites/toggle', $payload);
    }

    // ===================== التبديل =====================

    public function test_toggling_adds_then_removes(): void
    {
        // الزرّ نجمة: ضغطة تُضيف وضغطة تُزيل. واجهة إضافة وحدها تجعل
        // الإزالة تحتاج شاشة أخرى، فلا يزيلها أحد وتتراكم القائمة.
        $u = $this->customer();

        $this->toggle($u, ['kind' => 'contact', 'value' => '770001122', 'label' => 'أحمد'])
            ->assertOk()->assertJsonPath('favorited', true);

        $this->assertSame(1, FavouriteNumber::where('user_id', $u->id)->count());

        $this->toggle($u, ['kind' => 'contact', 'value' => '770001122'])
            ->assertOk()->assertJsonPath('favorited', false);

        $this->assertSame(0, FavouriteNumber::where('user_id', $u->id)->count());
    }

    /** @dataProvider kinds */
    public function test_every_kind_can_be_favorited(string $kind, string $value): void
    {
        $this->toggle($this->customer(), ['kind' => $kind, 'value' => $value, 'label' => 'اختبار'])
            ->assertOk()->assertJsonPath('favorited', true);
    }

    public static function kinds(): array
    {
        return [
            'جهة اتصال' => ['contact', '770001122'],
            'رقم حساب' => ['account', '54855507'],
            'عملية' => ['operation', '260726481037'],
            'تاجر' => ['merchant', 'M-00042'],
        ];
    }

    public function test_unknown_kind_is_rejected(): void
    {
        $this->toggle($this->customer(), ['kind' => 'anything', 'value' => 'x'])
            ->assertStatus(422);
    }

    public function test_phone_variants_resolve_to_one_favorite(): void
    {
        // نفس الرقم بثلاث صيغ كان يُنشئ ثلاث مفضّلات، ولا يُطابق أيّها الخصم.
        $u = $this->customer();

        $this->toggle($u, ['kind' => 'contact', 'value' => '770001122']);
        $this->toggle($u, ['kind' => 'contact', 'value' => '+967770001122'])
            ->assertJsonPath('favorited', false); // الثانية أزالت الأولى

        $this->assertSame(0, FavouriteNumber::where('user_id', $u->id)->count());
    }

    public function test_favorites_are_private_to_their_owner(): void
    {
        $mine = $this->customer();
        $other = $this->customer();

        $this->toggle($other, ['kind' => 'account', 'value' => '99999999']);

        $data = $this->actingAs($mine, 'api')
            ->getJson('/api/v1/amial/favorites')->assertOk()->json('data');

        $this->assertCount(0, $data);
    }

    // ===================== القائمة والفحص =====================

    public function test_list_can_be_filtered_by_kind(): void
    {
        $u = $this->customer();
        $this->toggle($u, ['kind' => 'contact', 'value' => '770001122']);
        $this->toggle($u, ['kind' => 'operation', 'value' => '260726481037']);

        $all = $this->actingAs($u, 'api')->getJson('/api/v1/amial/favorites')->json('data');
        $this->assertCount(2, $all);

        $ops = $this->actingAs($u, 'api')
            ->getJson('/api/v1/amial/favorites?kind=operation')->json('data');
        $this->assertCount(1, $ops);
        $this->assertSame('operation', $ops[0]['kind']);
    }

    public function test_check_reports_status_in_one_call(): void
    {
        // شاشة فيها عشرون عملية لا تحتمل عشرين نداءً لرسم النجوم.
        $u = $this->customer();
        $this->toggle($u, ['kind' => 'operation', 'value' => '111111111111']);

        $found = $this->actingAs($u, 'api')->postJson('/api/v1/amial/favorites/check', [
            'kind' => 'operation',
            'values' => ['111111111111', '222222222222', '333333333333'],
        ])->assertOk()->json('data');

        $this->assertSame(['111111111111'], $found);
    }

    public function test_delete_removes_only_own_favorite(): void
    {
        $mine = $this->customer();
        $other = $this->customer();

        $this->toggle($other, ['kind' => 'account', 'value' => '12345678']);
        $foreign = FavouriteNumber::where('user_id', $other->id)->first();

        $this->actingAs($mine, 'api')
            ->deleteJson("/api/v1/amial/favorites/{$foreign->id}")
            ->assertStatus(404);

        $this->assertNotNull($foreign->fresh());
    }

    // ===================== الحدّ =====================

    public function test_contact_limit_is_enforced_and_only_counts_contacts(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'favorite_number_limit'], ['value' => 2]
        );

        $u = $this->customer();
        $this->toggle($u, ['kind' => 'contact', 'value' => '770000001'])->assertOk();
        $this->toggle($u, ['kind' => 'contact', 'value' => '770000002'])->assertOk();

        $this->toggle($u, ['kind' => 'contact', 'value' => '770000003'])->assertStatus(422);

        // الحدّ لجهات الاتصال وحدها — العمليات والحسابات لا تُحسب معها.
        $this->toggle($u, ['kind' => 'operation', 'value' => '260726481037'])->assertOk();
        $this->toggle($u, ['kind' => 'account', 'value' => '54855507'])->assertOk();
    }

    // ===================== حراسة خصم الرسوم =====================

    public function test_non_contact_favorites_never_leak_into_the_fee_discount_list(): void
    {
        // العطل الذي يهدّد به التوسيع: قائمة الخصم تُبنى من عمود phone.
        // صفّ «عملية» بلا هاتف، أو رقم حساب في عمود القيمة، لو تسرّب إلى
        // المطابقة لمنح خصماً لمن لا يستحقّه أو منعه عمّن يستحقّه.
        $u = $this->customer();

        $this->toggle($u, ['kind' => 'contact', 'value' => '770001122']);
        $this->toggle($u, ['kind' => 'operation', 'value' => '260726481037']);
        $this->toggle($u, ['kind' => 'account', 'value' => '54855507']);

        $discountList = FavouriteNumber::where('user_id', $u->id)
            ->contacts()->pluck('phone')->toArray();

        $this->assertCount(1, $discountList);
        $this->assertStringContainsString('770001122', $discountList[0]);
        $this->assertNotContains('260726481037', $discountList);
        $this->assertNotContains('54855507', $discountList);
    }

    public function test_legacy_screen_still_reads_its_contacts(): void
    {
        // شاشة الأرقام المفضّلة القديمة ما زالت في التطبيق حتى يُبنى APK
        // جديد — يجب ألا تفرغ فجأة.
        $u = $this->customer();
        $this->toggle($u, ['kind' => 'contact', 'value' => '770001122', 'type' => 'f_and_f', 'label' => 'أخي']);

        // المسار القديم خلف CheckDeviceId — نُسجّل جهازاً لنصل إليه.
        \App\Models\UserLogHistory::create([
            'user_id' => $u->id, 'device_id' => 'dev', 'is_active' => 1,
        ]);

        $legacy = $this->actingAs($u, 'api')
            ->withHeader('device-id', 'dev')
            ->getJson('/api/v1/customer/favourite-number/list')
            ->assertOk()->json();

        $this->assertNotEmpty($legacy['f_and_f']);
    }

    public function test_guest_cannot_touch_favorites(): void
    {
        $this->postJson('/api/v1/amial/favorites/toggle', ['kind' => 'contact', 'value' => '77'])
            ->assertUnauthorized();
        $this->getJson('/api/v1/amial/favorites')->assertUnauthorized();
    }
}
