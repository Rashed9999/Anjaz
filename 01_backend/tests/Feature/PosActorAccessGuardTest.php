<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ACTOR-001 — المالكُ وموظّفُ نقطة البيع: صنفٌ واحد، بابان.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:**
 *
 * `FeatureAccessService::accessFor()` كانت تعالج `ROLE_MERCHANT` وحده.
 * وموظّفُ نقطة البيع دورُه `'pos'` — **وهو ليس في `ALL_ROLES` أصلاً**.
 * فكان الخادمُ يردّ له:
 *
 *     business_type = null · plan = free · features = الأساس وحده
 *
 * أي أنّ **كاشيرَ محطّة الوقود يفتح التطبيق فلا يجد ميزةَ وقودٍ واحدة**،
 * والخادمُ يقول إنّه بلا صنفٍ وبلا اشتراك. ولا خطأ في أيّ سجلّ: الردُّ
 * ٢٠٠ وقائمةُ الميزات صحيحةُ الشكل، فارغةُ المعنى.
 *
 * **ولا فرقَ مصرَّحٌ به بين المالك والموظّف**، فشاشةٌ واحدةٌ للاثنين:
 * يرى الكاشيرُ ما يرى صاحبُ المحطّة.
 */
class PosActorAccessGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:User} المالك ثمّ الموظّف */
    private function station(array $permissions = []): array
    {
        $owner = User::factory()->create(['role' => A::ROLE_MERCHANT]);

        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_FUEL,
            'subscription_plan' => A::PLAN_BUSINESS,
            'verification_status' => 'verified',
        ]);

        $staff = User::factory()->create(['role' => 'pos']);

        PosUser::create([
            'user_id' => $staff->id,
            'merchant_user_id' => $owner->id,
            'pos_number' => 'POS-1',
            'display_name' => 'كاشير المضخّة',
            'is_active' => true,
            'permissions' => $permissions,
        ]);

        return [$owner, $staff];
    }

    /**
     * @test
     *
     * **موظّفُ نقطة البيع يرث صنفَ محطّته — فيجد كاشيرَ الوقود.**
     */
    public function a_pos_staff_inherits_the_station_business_type(): void
    {
        [, $staff] = $this->station();

        $access = app(FeatureAccessService::class)->accessFor($staff);

        $this->assertSame('pos', $access['actor'],
            'الفاعلُ غيرُ مصرَّحٍ به — فلا يعرف التطبيقُ أيَّ شاشةٍ يفتح');

        $this->assertSame(A::BIZ_FUEL, $access['business_type'],
            'الموظّفُ بلا صنف — يفتح التطبيقَ فلا يجد ميزةَ وقودٍ واحدة');

        $this->assertContains(A::F_FUEL_POS, $access['features'],
            'كاشيرُ الوقود غائبٌ عن موظّف محطّة وقود');
    }

    /**
     * @test
     *
     * **وصلاحيّاتٌ فارغةٌ تعني البيعَ وحده — لا كلَّ شيء.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا أخطرُ اتّجاهٍ في الخطأ.** فلو ورث الموظّفُ كلَّ شيءٍ لرأى
     * أسعارَ المحطّة وورديّاتِها وتقاريرَها وموظّفيها — **ومن نُسي ضبطُه
     * يفتح خزنةَ صاحبه**. والافتراضيُّ في المال أضيقُ الدائرتين.
     */
    public function empty_permissions_mean_selling_only(): void
    {
        [, $staff] = $this->station([]);

        $f = app(FeatureAccessService::class)->accessFor($staff)['features'];

        $this->assertContains(A::F_FUEL_POS, $f, 'البيعُ نفسُه مُنع');
        $this->assertContains(A::F_RECEIPTS, $f, 'طباعةُ الإيصال مُنعت');

        foreach ([A::F_FUEL_PUMPS, A::F_FUEL_COMPANIES, A::F_FUEL_SHIFTS,
                  A::F_DAILY_REPORTS] as $forbidden) {
            $this->assertNotContains($forbidden, $f,
                "موظّفٌ بلا صلاحيّاتٍ يرى «{$forbidden}» — يفتح خزنةَ صاحبه");
        }
    }

    /**
     * @test
     *
     * **وما مُنح صراحةً يُرى — ولا شيءَ غيره.**
     */
    public function granted_permissions_are_honoured_exactly(): void
    {
        [, $staff] = $this->station([A::F_FUEL_SHIFTS]);

        $f = app(FeatureAccessService::class)->accessFor($staff)['features'];

        $this->assertContains(A::F_FUEL_SHIFTS, $f,
            'صلاحيّةٌ مُنحت ولم تصل');

        $this->assertNotContains(A::F_FUEL_COMPANIES, $f,
            'وصلت صلاحيّةٌ لم تُمنح — القصُّ لا يعمل');
    }

    /**
     * @test
     *
     * **والمالكُ يرى صنفَه كاملاً — ولا يُقصّ بصلاحيّات أحد.**
     *
     * فالقصُّ للموظّف وحده. ولو امتدّ إلى المالك لأغلقنا محطّةً على صاحبها.
     */
    public function the_owner_sees_the_whole_station(): void
    {
        [$owner] = $this->station();

        $access = app(FeatureAccessService::class)->accessFor($owner);

        $this->assertSame('owner', $access['actor']);
        $this->assertNull($access['pos'], 'المالكُ يحمل بياناتِ نقطة بيع');

        foreach ([A::F_FUEL_POS, A::F_FUEL_PUMPS, A::F_FUEL_COMPANIES,
                  A::F_FUEL_SHIFTS, A::F_DAILY_REPORTS] as $f) {
            $this->assertContains($f, $access['features'],
                "المالكُ لا يرى «{$f}» في محطّته");
        }
    }

    /**
     * @test
     *
     * **والتعديلُ لا يمسّ صنفاً آخر.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **شرطُ صاحب المشروع صراحةً**: «التعديلات لمحطّة الوقود فقط، ولكلّ
     * صنفٍ أزرارُه».
     *
     * فيُقاس أنّ صيدليّةً لم تكتسب ميزةَ وقودٍ ولم تفقد ميزتَها. وهذا ما
     * يمنع «تحسيناً» لصنفٍ يسرّب إلى الأصناف الخمسة الأخرى.
     */
    public function other_business_types_are_untouched(): void
    {
        $ph = User::factory()->create(['role' => A::ROLE_MERCHANT]);

        MerchantProfile::create([
            'user_id' => $ph->id,
            'business_type' => A::BIZ_PHARMACY,
            'subscription_plan' => A::PLAN_BUSINESS,
            'verification_status' => 'verified',
        ]);

        $f = app(FeatureAccessService::class)->accessFor($ph)['features'];

        $this->assertContains(A::F_PHARMACY_POS, $f, 'الصيدليّةُ فقدت كاشيرَها');
        $this->assertContains(A::F_PHARMACY_BATCHES, $f, 'الصيدليّةُ فقدت التشغيلات');

        $this->assertNotContains(A::F_FUEL_PUMPS, $f,
            'صيدليّةٌ اكتسبت مضخّاتِ وقود — التعديلُ تسرّب إلى صنفٍ آخر');
    }

    /**
     * @test
     *
     * **وموظّفٌ عُطِّل حسابُه لا يرث شيئاً.**
     *
     * فمن فُصل من المحطّة يبقى مستخدمُه قائماً؛ ولو ورث بعد التعطيل
     * لظلّ يبيع باسمها.
     */
    public function a_deactivated_staff_inherits_nothing(): void
    {
        [, $staff] = $this->station([A::F_FUEL_SHIFTS]);

        PosUser::where('user_id', $staff->id)->update(['is_active' => false]);

        $access = app(FeatureAccessService::class)->accessFor($staff->fresh());

        $this->assertNotSame('pos', $access['actor'],
            'موظّفٌ معطَّلٌ ما زال فاعلاً في نقطة بيع');

        $this->assertNotContains(A::F_FUEL_POS, $access['features'],
            'موظّفٌ فُصل ما زال يبيع باسم المحطّة');
    }
}
