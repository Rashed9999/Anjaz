<?php

namespace Tests\Feature;

use App\Models\Merchant\PosDevice;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Merchant\PosDeviceRegistrar;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-POS-DEVICES-002 — **سرُّ البصمة يُدوَّر، والهويّةُ تبقى.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي مُنع قبل أن يُدفع:**
 *
 * كانت البصمةُ تُجزَّأ بـ`APP_KEY`. و`APP_KEY` **يُدوَّر لأسبابٍ لا علاقةَ
 * لها بالأجهزة** — تسريبٌ مشتبَه، أو نقلُ خادم، أو إجراءٌ دوريّ. وأوّلُ
 * تدويرٍ له كان سيُنتج هذا:
 *
 *   كلُّ بصمةٍ مخزَّنةٍ تصير مجهولة
 *     ⇒ كلُّ جهازٍ يعود يُقرأ **جهازاً جديداً**
 *       ⇒ يستهلك مقعداً، والمقاعدُ مشغولةٌ بأشباح نفسِه
 *         ⇒ `limit_reached` على كلّ متجرٍ في البلد صباحاً، **بلا سبب**
 *
 * ولا خطأَ في أيّ سجلّ: الشيفرةُ تعمل تماماً كما كُتبت.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والعلاجُ ثلاثُ قطعٍ لا واحدة:**
 *
 *   ① سرٌّ مستقلّ    `AMIAL_DEVICE_HASH_KEY`  — لا يُدوَّر مع غيره
 *   ② إصدارٌ مخزَّنٌ   `hash_key_version`       — فيُعرف بأيِّها جُزّئ الصفّ
 *   ③ مفاتيحُ سابقة  `..._KEYS_PREVIOUS`      — تُقرأ ولا يُكتب بها
 *
 * فالتدويرُ يصير **ذوباناً لا قطعاً**: القديمُ يُقارَن بمفتاحه، ويُرحَّل
 * إلى الجديد عند أوّل ظهور، ثمّ يُحذف المفتاحُ القديمُ بلا يومٍ يُقفَل
 * فيه متجرٌ واحد.
 *
 * **وهذا الملفُّ يُثبت الثلاثةَ بالعمل، ويُثبت بالعكس أنّ المفتاحَ يعمل
 * فعلاً** — فاختبارٌ يمرّ لأنّ التجزئةَ تتجاهل المفتاحَ أصلاً يُطمئن ولا
 * يحرس (القاعدة الثانية).
 */
class PosDeviceIdentityRotationGuardTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_OLD = 'device-key-generation-one-0000000';

    private const KEY_NEW = 'device-key-generation-two-1111111';

    private const UUID = 'installation-id-abcd-9931';

    private function merchant(string $plan = A::PLAN_FREE): User
    {
        $u = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL, 'subscription_plan' => $plan,
        ]);

        return $u->refresh();
    }

    private function reg(): PosDeviceRegistrar
    {
        return app(PosDeviceRegistrar::class);
    }

    /** يضبط جيلَ المفاتيح الجاري. */
    private function useKeys(string $current, int $version, string $previous = ''): void
    {
        config([
            'amial.device_identity.hash_key' => $current,
            'amial.device_identity.hash_key_version' => $version,
            'amial.device_identity.previous_keys' => $previous,
        ]);
    }

    /**
     * @test
     *
     * **التدويرُ لا يُنتج جهازاً ثانياً، ولا يستهلك مقعداً.**
     *
     * وهذه هي الجملةُ التي لو سقطت أُقفلت المتاجرُ صباحاً.
     */
    public function rotating_the_key_keeps_the_device_and_takes_no_second_seat(): void
    {
        $m = $this->merchant(A::PLAN_FREE);   // مقعدٌ واحدٌ فقط — فالمجالُ لا يحتمل خطأً

        $this->useKeys(self::KEY_OLD, 1);

        $first = $this->reg()->register($m, self::UUID);

        $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED, $first['result']);
        $this->assertSame(1, (int) $first['device']->hash_key_version,
            'الصفُّ لم يحفظ إصدارَ المفتاح الذي جُزّئ به — فالتدويرُ لن يعرف بأيِّها يقارن');

        // ══════════════════════════════════════════════════════════
        //  **التدوير** — مفتاحٌ جديد، والقديمُ ينتقل إلى قائمة القراءة.
        $this->useKeys(self::KEY_NEW, 2, '1:'.self::KEY_OLD);

        $again = $this->reg()->register($m, self::UUID);

        $this->assertSame(PosDeviceRegistrar::RESULT_EXISTING, $again['result'],
            'الجهازُ نفسُه قُرئ جهازاً جديداً بعد التدوير — **وهذا هو العطلُ '
            . 'الذي يُقفل كلَّ متجرٍ دفعةً واحدة**');

        $this->assertSame(1, PosDevice::activeSeats($m->id),
            'التدويرُ استهلك مقعداً ثانياً لجهازٍ واحد');
    }

    /**
     * @test
     *
     * **وبالعكس: بلا قائمةِ المفاتيح السابقة تضيع الهويّة.**
     *
     * وهذا ما يجعل الاختبارَ السابقَ اختباراً: لو كانت التجزئةُ تتجاهل
     * المفتاحَ أصلاً لمرّ ذاك بلا أن يحرس شيئاً. فهنا يُدوَّر المفتاحُ
     * **بلا** إبقاءِ القديم، ويجب أن **يسقط** التعرّف.
     */
    public function without_the_previous_key_the_identity_is_genuinely_lost(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $this->useKeys(self::KEY_OLD, 1);
        $this->reg()->register($m, self::UUID);

        // تدويرٌ أعمى: لا `previous_keys`.
        $this->useKeys(self::KEY_NEW, 2, '');

        $blind = $this->reg()->register($m, self::UUID);

        $this->assertNotSame(PosDeviceRegistrar::RESULT_EXISTING, $blind['result'],
            'الجهازُ عُرف بمفتاحٍ لم يُجزَّأ به ولا مفتاحَ سابقٌ مُعلَن — '
            . '**فالمفتاحُ لا يشارك في التجزئة أصلاً، والحارسُ السابقُ لا يحرس**');

        $this->assertSame(PosDeviceRegistrar::RESULT_LIMIT, $blind['result'],
            'المقعدُ الوحيدُ مشغولٌ بالشبح، فالمتوقَّعُ رفضٌ بالحدّ');
    }

    /**
     * @test
     *
     * **والترحيلُ يقع عند أوّل ظهور، فيُحذف المفتاحُ القديمُ بعده بلا فقد.**
     *
     * وبدون هذا يبقى المفتاحُ القديمُ حيّاً أبداً — أي أنّ التدويرَ لم يقع.
     */
    public function the_row_is_migrated_so_the_old_key_can_be_dropped(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $this->useKeys(self::KEY_OLD, 1);
        $device = $this->reg()->register($m, self::UUID)['device'];

        $this->useKeys(self::KEY_NEW, 2, '1:'.self::KEY_OLD);
        $this->reg()->register($m, self::UUID);

        $device->refresh();

        $this->assertSame(2, (int) $device->hash_key_version,
            'الصفُّ لم يُرحَّل إلى الإصدار الجديد — فالمفتاحُ القديمُ يبقى لازماً أبداً');

        $this->assertSame(PosDevice::hashUuid(self::UUID, self::KEY_NEW), $device->device_uuid_hash,
            'البصمةُ المخزَّنةُ ما زالت بالمفتاح القديم');

        // **وبعد الترحيل يُحذف القديمُ ولا يُفقد شيء** — وهو تمامُ الدورة.
        $this->useKeys(self::KEY_NEW, 2, '');

        $this->assertSame(PosDeviceRegistrar::RESULT_EXISTING,
            $this->reg()->register($m, self::UUID)['result'],
            'حذفُ المفتاح القديم بعد الترحيل أضاع الجهاز — فالترحيلُ لم يكتمل');
    }

    /**
     * @test
     *
     * **ولا اشتقاقَ من `APP_KEY` — لا صراحةً ولا صامتاً.**
     *
     * ومفتاحٌ فارغٌ **يسقط برسالةٍ** ولا يشتقّ بديلاً: فالاشتقاقُ الصامتُ
     * يُعيد العلّةَ نفسَها ويُخفيها خلف سلوكٍ يبدو سليماً.
     */
    public function the_device_secret_is_never_derived_from_the_application_key(): void
    {
        $this->useKeys(self::KEY_OLD, 1);

        $this->assertNotSame(
            hash_hmac('sha256', self::UUID, (string) config('app.key')),
            PosDevice::hashUuid(self::UUID),
            'البصمةُ تُجزَّأ بـ`APP_KEY` — وتدويرُه يمحو هويّةَ كلّ الأجهزة');

        $this->assertNotSame(
            PosDevice::hashUuid(self::UUID, self::KEY_OLD),
            PosDevice::hashUuid(self::UUID, self::KEY_NEW),
            'المفتاحُ لا يغيّر البصمة — أي أنّه ليس مفتاحاً');

        config(['amial.device_identity.hash_key' => '']);

        $this->expectException(\RuntimeException::class);

        PosDevice::hashUuid(self::UUID);
    }
}
