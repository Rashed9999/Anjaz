<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-POS-SCOPE-001 — **رصيدُ المتجر لا يخرج إلى الكاشير.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس قبل هذا الحارس:**
 *
 * `merchant/daily-stats` يردّ `current_balance` — **محفظةَ صاحب المتجر
 * كاملةً** — لموظّف نقطة البيع، لأنّ `resolveMerchantPos` تحوّل الموظّفَ
 * إلى صاحبه فتُقرأ محفظةُ الأخير.
 *
 * **وأختُه تحرس نفسَها**: `financialReport` في المتحكّم نفسِه يردّ موظّفَ
 * نقطة البيع صراحةً بـ«التقرير المالي الكامل متاح لمالك المتجر فقط».
 * فالحمايةُ كانت في واحدٍ وغائبةً عن الآخر — **والآخرُ هو الذي تقرؤه
 * لوحةُ التاجر في كلّ فتحة**، وهي الشاشةُ التي كان يهبط فيها الكاشيرُ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُصفَّر بل يُحذف.** صفرٌ يُقرأ «فحصنا فوجدنا المتجرَ خاوياً»،
 * وهو كذب. (القاعدة السابعة: «غير معروف» ليس صفراً.)
 *
 * **وما يبقى للموظّف مقصود**: مبيعاتُ اليوم والمرتجعاتُ والعدد —
 * يحتاجها ليُقفل ورديّتَه. فحارسٌ يحجب كلَّ شيء يشلّ عملاً سليماً.
 */
class PosBalanceScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/v1/amial/merchant/daily-stats';

    private int $seq = 0;

    /**
     * ══════════════════════════════════════════════════════════════════
     * **بوّابةُ المقعد تُطفَأ هنا عمداً — ويُقال لماذا.**
     *
     * `EnsurePosDevice` تردّ ٤٠٣ لكلّ موظّف نقطة بيعٍ **لا ربطَ لجلسته
     * بجهاز**. والربطُ يُقرأ من معرِّف رمز Passport، و`actingAs` لا
     * تُصدر رمزاً — فكلُّ موظّفٍ في أيّ اختبارٍ «بلا مقعد» بالضرورة،
     * ولا سبيلَ إلى ربطه هنا.
     *
     * فلو تُرك الإنفاذُ لَحرس هذا الملفُّ **بوّابةَ المقعد** ظنّاً منه
     * أنّه يحرس الرصيد: يخرج أخضرَ لأنّ الطلبَ رُدّ عند الباب،
     * **والرصيدُ لم يُفحص أصلاً**. وهذا بعينه «حارسٌ يمرّ والعطلُ قائم».
     *
     * **وبوّابةُ المقعد محروسةٌ في موضعها** — `PosDeviceEnforcementTest`
     * وأخواتُها — فإطفاؤها هنا لا يترك ثغرةً بلا حارس. والكاشيرُ في
     * الإنتاج يمرّ منها بجهازٍ مربوطٍ ثمّ يصل إلى هذا المسار، **وهي
     * الحالةُ التي يُقاس فيها الرصيد**.
     * ══════════════════════════════════════════════════════════════════
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('amial.pos_devices.enforce_session_binding', false);
    }

    private function merchant(): User
    {
        $this->seq++;

        $m = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'is_active' => 1,
            'phone' => '9677401' . str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
        ]);

        MerchantProfile::create([
            'user_id' => $m->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        return $m->fresh();
    }

    /** موظّفُ نقطة بيعٍ حقيقيّ — **من المسار الذي يستعمله التطبيق**. */
    private function cashierOf(User $merchant): User
    {
        $r = $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/staff', [
                'employee_code' => 'E' . $this->seq,
                'display_name' => 'كاشير الحارس',
                'password' => 'pos12345',
                'permissions' => [],
            ]);

        $r->assertStatus(201);

        $pos = PosUser::where('merchant_user_id', $merchant->id)->firstOrFail();
        $cashier = User::findOrFail($pos->user_id);

        $this->assertSame('pos', $cashier->role,
            'الموظّفُ المُنشأ ليس دورَ نقطة بيع — الحارسُ يفحص حالةً غير قائمة');

        return $cashier;
    }

    /**
     * @test
     *
     * **المالكُ يرى رصيدَه** — وإلّا كان الحارسُ قفلاً لا حماية.
     */
    public function the_owner_still_sees_the_store_balance(): void
    {
        $r = $this->actingAs($this->merchant(), 'api')->getJson(self::ROUTE);

        $r->assertStatus(200);

        $this->assertNotNull($r->json('meta.current_balance'),
            '**حُجب الرصيدُ عن صاحب المتجر نفسِه** — فالحارسُ عطلٌ لا حماية.');

        $this->assertNull($r->json('meta.balance_scope'),
            'وُسم ردُّ المالك بحدٍّ لا ينطبق عليه');
    }

    /**
     * @test
     *
     * **والكاشيرُ لا يراه.** وهو الغرضُ من الحارس.
     */
    public function a_pos_employee_never_receives_the_store_balance(): void
    {
        $merchant = $this->merchant();
        $cashier = $this->cashierOf($merchant);

        $r = $this->actingAs($cashier, 'api')->getJson(self::ROUTE);

        $r->assertStatus(200);

        $body = $r->json('meta') ?? [];

        $this->assertArrayNotHasKey('current_balance', $body,
            "**رصيدُ المتجر وصل إلى الكاشير:** "
            . (string) ($body['current_balance'] ?? '')
            . "\n\nوالمسارُ يقرأ محفظةَ صاحبه لأنّ `resolveMerchantPos` "
            . "تحوّل الموظّفَ إليه. **ولا خطأَ في أيّ سجلّ**: الردُّ ٢٠٠ "
            . 'وصيغتُه صحيحة، والرقمُ ليس له.');

        $this->assertSame('owner_only', $body['balance_scope'] ?? null,
            'حُذف الرصيدُ ولم يُقَل لماذا — فالتطبيقُ لا يفرّق بين '
            . '«محجوب» و«تعذّرت القراءة»، ويكتب أحدَهما مكانَ الآخر.');
    }

    /**
     * @test
     *
     * **وما يحتاجه لورديّته يبقى.** فحجبٌ شاملٌ يشلّ إقفالَ الصندوق.
     */
    public function the_cashier_still_gets_what_a_shift_needs(): void
    {
        $cashier = $this->cashierOf($this->merchant());

        $body = $this->actingAs($cashier, 'api')->getJson(self::ROUTE)->json('meta') ?? [];

        foreach (['today_sales', 'today_refunds', 'today_net', 'today_count'] as $key) {
            $this->assertArrayHasKey($key, $body,
                "حُجب «{$key}» عن الكاشير — **وهو يحتاجه ليُقفل ورديّتَه**، "
                . 'فالحارسُ صار قفلاً على عملٍ سليم.');
        }
    }

    /**
     * @test
     *
     * **والحمايةُ في المسار لا في الشاشة.**
     *
     * فلو بقيت في الواجهة وحدَها لَقرأها من فتح المسارَ بأيّ أداة.
     * ويُقاس بأنّ الشاشةَ لا تُسأل أصلاً هنا: الفحصُ على ردّ HTTP.
     * وهذا الاختبارُ يوثّق أنّ الأختَ محروسةٌ أيضاً فلا تُنسى غداً.
     */
    public function the_full_financial_report_stays_owner_only(): void
    {
        $cashier = $this->cashierOf($this->merchant());

        $this->actingAs($cashier, 'api')
            ->getJson('/api/v1/amial/merchant/financial-report')
            ->assertStatus(403);
    }
}
