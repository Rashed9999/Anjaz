<?php

namespace Tests\Feature;

use App\Models\CashierShift;
use App\Models\Merchant\PosDevice;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\Merchant\PosDeviceRegistrar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AMIAL-SHIFT-DEVICE-001 — **ترابطُ الموظّف والصندوق والورديّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قال صاحبُ المشروع:** «الموظّفين + نقاط البيع POS + الورديّات — يجب
 * أن يكون بينهم ترابطٌ متين».
 *
 * وقِيس المثلّثُ قبل أن يُكتب سطر:
 *
 *     موظّف ──✓── ورديّة   `pos_user_id` · `opened_by` · `opened_by_role`
 *     ورديّة ──✓── بيعة    `merchant_sales.shift_id`
 *     جهاز  ──✗── ورديّة   **لا عمودَ إطلاقاً**
 *     جهاز  ──✗── بيعة    **لا عمودَ إطلاقاً**
 *
 * فالجهازُ يُسجَّل ويُحدَّد بمقاعد الباقة ويُلغى عند الفقد — **ومفصولٌ عن
 * المال تماماً**. وأخطرُ ما في ذلك أنّ إلغاءَ جهازٍ مسروقٍ كان **يُعطّل
 * الصندوق**: الجلساتُ تنتهي، والورديّةُ تبقى مفتوحةً لا يستطيع أحدٌ
 * إقفالَها، وقفلُها يمنع فتحَ غيرها.
 */
class ShiftDeviceStaffBindingGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        return User::factory()->create(['type' => MERCHANT_TYPE, 'is_active' => 1]);
    }

    private function device(User $m, string $hint = 'till-1'): PosDevice
    {
        $d = new PosDevice();
        $d->merchant_user_id = $m->id;
        $d->device_uuid_hash = hash('sha256', $hint.$m->id);
        $d->hash_key_version = 1;
        $d->device_hint = $hint;
        $d->display_name = $hint;
        $d->registered_at = now();
        $d->is_active = true;
        $d->save();

        return $d;
    }

    /** @test */
    public function a_shift_now_records_which_till_it_was_opened_on(): void
    {
        // **العمودُ المفقود.** الورديّةُ كانت تعرف *من* فتحها ولا تعرف
        // *على أيّ صندوق* — فسؤالُ «كم قبض هذا الصندوق اليوم» بلا جواب.
        $this->assertTrue(Schema::hasColumn('cashier_shifts', 'pos_device_id'));
        $this->assertTrue(Schema::hasColumn('merchant_sales', 'pos_device_id'));

        $m = $this->merchant();
        $d = $this->device($m);

        $shift = app(CashierShiftService::class)->open($m, null, '1000', $d->id);

        $this->assertSame($d->id, $shift->pos_device_id,
            'الورديّةُ لا تحمل صندوقَها — فالجهازُ يبقى مفصولاً عن المال');
    }

    /** @test */
    public function one_till_never_carries_two_open_shifts(): void
    {
        // **درجُ النقد واحدٌ ماديّاً.** وورديّتان عليه تعنيان أنّ الجردَ
        // لا يُنسَب لأحد: كلٌّ يقول «العجزُ من الآخر» ولا يُحسم.
        $m = $this->merchant();
        $d = $this->device($m);
        $svc = app(CashierShiftService::class);

        $svc->open($m, null, '1000', $d->id);

        $staff = User::factory()->create(['type' => 4, 'is_active' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ورديّةٌ مفتوحة/u');

        $svc->open($m, $staff->id, '500', $d->id);
    }

    /** @test */
    public function the_database_itself_refuses_the_second_open_shift_on_a_till(): void
    {
        // **والفحصُ في الخدمة رسالةٌ تُقرأ، والقيدُ هو الحارس**: موظّفان
        // يضغطان «افتح» في اللحظة نفسِها يقرآن كلاهما «لا ورديّةَ مفتوحة».
        // (وهو الدرسُ الذي دُفع ثمنُه في هذا المشروع مرّتين.)
        $m = $this->merchant();
        $d = $this->device($m);

        app(CashierShiftService::class)->open($m, null, '1000', $d->id);

        $this->expectException(UniqueConstraintViolationException::class);

        CashierShift::create([
            'merchant_user_id' => $m->id,
            'pos_device_id' => $d->id,
            'open_device_lock' => $d->id,   // ← يتخطّى فحصَ الخدمة عمداً
            'opening_float' => '0',
            'status' => 'open',
            'opened_by' => $m->id,
            'opened_by_name' => 'مباشرةً',
            'opened_at' => now(),
        ]);
    }

    /** @test */
    public function closing_a_shift_frees_the_till_for_the_next_person(): void
    {
        // وقفلٌ لا يُحرَّر يجعل الصندوقَ يعمل مرّةً واحدةً في عمره.
        $m = $this->merchant();
        $d = $this->device($m);
        $svc = app(CashierShiftService::class);

        $first = $svc->open($m, null, '1000', $d->id);
        $svc->close($first, '1000', null, $m);

        $this->assertNull($first->fresh()->open_device_lock);

        // **ويبقى `pos_device_id`** — هو التاريخُ الذي يقول أين جرت.
        $this->assertSame($d->id, $first->fresh()->pos_device_id);

        $second = $svc->open($m, null, '2000', $d->id);
        $this->assertSame('open', $second->status);
    }

    /** @test */
    public function revoking_a_lost_till_never_leaves_its_drawer_open_forever(): void
    {
        // **أخطرُ ما كُشف.** الإلغاءُ كان يُنهي الجلساتِ ويخلي المقعد،
        // **وتبقى الورديّةُ مفتوحةً إلى الأبد**: لا أحدَ يُقفلها (الجهازُ
        // لا يستجيب)، وقفلُها يمنع فتحَ غيرها — فإلغاءُ جهازٍ مسروقٍ
        // **يُعطّل الصندوق**.
        $m = $this->merchant();
        $d = $this->device($m);
        $svc = app(CashierShiftService::class);

        $shift = $svc->open($m, null, '1000', $d->id);

        app(PosDeviceRegistrar::class)->revoke($d, $m->id);

        $after = $shift->fresh();

        $this->assertSame('abandoned', $after->status,
            'بقيت الورديّةُ مفتوحةً بعد إلغاء جهازها');
        $this->assertNull($after->open_device_lock,
            'بقي القفلُ على جهازٍ ملغىً — فلا يُفتَح صندوقٌ بديل');
        $this->assertNotEmpty($after->notes);
    }

    /** @test */
    public function an_abandoned_shift_is_never_given_an_invented_variance(): void
    {
        // **القاعدة السابعة.** إقفالُها بصفرٍ يكتب فرقاً كاذباً في سجلٍّ
        // ماليّ، وبالمتوقَّع يُخفي السرقةَ التي أُلغي الجهازُ بسببها.
        // فالدرجُ لم يُعَدّ، ويُقال ذلك.
        $m = $this->merchant();
        $d = $this->device($m);

        $shift = app(CashierShiftService::class)->open($m, null, '1000', $d->id);

        app(PosDeviceRegistrar::class)->revoke($d, $m->id);

        $after = $shift->fresh();

        $this->assertNull($after->counted_cash,
            'كُتب مبلغٌ معدودٌ لدرجٍ لم يعدَّه أحد');
        $this->assertNull($after->variance,
            'كُتب فرقُ جردٍ على ورديّةٍ لم تُجرَد — وهو رقمٌ مخترَعٌ في سجلٍّ ماليّ');
    }

    /** @test */
    public function a_shift_opened_without_a_till_stays_legal_and_says_so(): void
    {
        // **و`null` ليست عطلاً**: تاجرٌ يبيع من هاتفه بلا صندوقٍ مسجَّل.
        // وحاجزٌ يشترط جهازاً يشلّ عملاً سليماً.
        $m = $this->merchant();

        $a = app(CashierShiftService::class)->open($m, null, '100', null);
        $this->assertNull($a->pos_device_id);
        $this->assertNull($a->open_device_lock);

        // **ولا يتزاحم اثنان بلا جهاز على القفل** — `NULL` يتكرّر تحت
        // القيد الفريد، وهو المقصود.
        $other = $this->merchant();
        $b = app(CashierShiftService::class)->open($other, null, '100', null);
        $this->assertSame('open', $b->status);
    }

    /** @test */
    public function the_till_on_a_sale_is_read_from_its_shift_and_never_from_the_request(): void
    {
        // **مصدرٌ واحدٌ للجهاز.** قراءتُه ثانيةً من الطلب تفتح بابَ
        // فاتورةٍ تقول صندوقاً ودرجُها في آخر. (القاعدة الثامنة.)
        $code = implode("\n", array_filter(
            explode("\n", (string) file_get_contents(app_path('Services/CashierService.php'))),
            fn ($l) => ! str_starts_with(ltrim($l), '//')));

        $this->assertStringContainsString('$openShift?->pos_device_id', $code,
            'البيعةُ تقرأ الجهازَ من غير ورديّتها');
        $this->assertStringContainsString("'pos_device_id' => \$saleDeviceId", $code);
    }

    /** @test */
    public function the_shift_door_takes_the_till_from_the_gate_not_from_the_body(): void
    {
        // معرّفٌ يأتي من الجهاز يمكن تغييرُه، والبوّابةُ وحدَها أثبتت أنّه
        // غيرُ ملغىً ومطابقٌ للجلسة.
        $code = implode("\n", array_filter(
            explode("\n", (string) file_get_contents(
                app_path('Http/Controllers/Api/V1/Amial/CashierShiftController.php'))),
            fn ($l) => ! str_starts_with(ltrim($l), '//')));

        $this->assertStringContainsString('EnsurePosDevice::deviceOf($request)', $code,
            'الشاشةُ تُملي رقمَ الصندوق بدل أن تأخذه البوّابةُ من الجلسة');
        $this->assertStringNotContainsString("input('pos_device_id')", $code);
    }
}
