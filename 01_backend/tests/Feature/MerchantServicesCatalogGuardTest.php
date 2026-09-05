<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-PLAN-TRUTH-001 — **رقمان لشيءٍ واحد، وكلاهما صحيح.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * أرسل صاحبُ المشروع صورتين لحسابٍ واحدٍ على **باقة مؤسسة**:
 *
 *     «مزايا باقتي»  →  ٤٩ متاحة
 *     «خدمات التاجر» →  مفتوحٌ لديك ٢٩ من ٢٩ خدمة
 *
 * وقال: «هناك مشكلة، هذا التناقض في المميزات».
 *
 * **وقِيس، فليس أحدُهما خطأً في الحساب — هما يعدّان شيئين:**
 *
 *   · «مزايا باقتي» تقرأ `/me/entitlements` ← `CapabilityRegistry`
 *     في الخادم، وفيه **٧١ قدرة**.
 *   · و«خدمات التاجر» تقرأ `_catalog` **مكتوباً يدويّاً في ملفّ Dart**،
 *     وفيه **٣٠ عنصراً** — وهي ما لها شاشةٌ في التطبيق.
 *
 * **والعطلُ أنّ الاثنين يسمّيان ما يعدّانه «خدمة».** فصار العدّادُ الثاني
 * يقول «شاشة في التطبيق» ويدلّ على الأوّل.
 *
 * **وجذرُ العطل باقٍ ما بقيت قائمةٌ مكتوبةٌ بيد**: تُضاف قدرةٌ في الخادم
 * فلا تظهر في تلك القائمة **أبداً**، ولا شيءَ يُنبّه. وهذا نمطُ «قائمةٌ
 * موازيةٌ تشيخ» المكتوبُ في `CLAUDE.md` — وقع في مرشّحات سجلّ التدقيق،
 * وفي `AccessPresets` مقابل `CapabilityRegistry`، وهنا ثالثةً.
 *
 * **فهذا الحارسُ يجعل الشيخوخةَ مسموعة**: كلُّ رمزٍ في قائمة Dart لا بدّ
 * أن يكون قدرةً حقيقيّةً في السجلّ. ورمزٌ مكتوبٌ خطأً — أو أُعيدت تسميتُه
 * في الخادم — يُنتج بلاطةً **لا تُفتَح أبداً** لأنّ `access.has()` لا
 * تعرفها، ولا خطأَ في أيّ سجلّ.
 */
class MerchantServicesCatalogGuardTest extends TestCase
{
    private const HUB = '../02_flutter_app/lib/features/merchant/screens/'
        .'merchant_services_hub_screen.dart';

    /** رموزُ القدرات في قائمة Dart المكتوبة يدويّاً. */
    private function catalogCodes(): array
    {
        $src = (string) file_get_contents(base_path(self::HUB));

        // `_Svc('code', 'العنوان', …)` — الوسيطُ الأوّلُ هو الرمز.
        preg_match_all("/_Svc\(\s*'([a-z0-9_.]+)'/", $src, $m);

        return array_values(array_unique($m[1]));
    }

    /** @test */
    public function every_service_tile_names_a_capability_the_server_knows(): void
    {
        $codes = $this->catalogCodes();

        // **ومُطابِقٌ يخرج بصفرٍ يمرّ أخضرَ على كلّ شيء** — والصمتُ بثوب
        // نجاح. فيُشترَط أن تكون القائمةُ قد قُرئت أصلاً.
        $this->assertGreaterThan(20, count($codes),
            'لم تُقرأ قائمةُ الخدمات — تغيّرت صيغةُ `_Svc(` فصار الحارسُ '
            .'يفحص فراغاً ويخرج أخضر');

        // ══════════════════════════════════════════════════════════
        // **والمقارنةُ على العقد الذي تقرؤه الشاشةُ فعلاً.**
        //
        // أوّلُ صيغةٍ لهذا الحارس قارنت بـ`CapabilityRegistry` **فسقطت
        // على `receipts` و`wallet`** — وهما ليسا خطأً: `F_RECEIPTS`
        // و`F_WALLET` علمان حقيقيّان في `AccessConstants` يمنحهما
        // `AccessPresets`، **وليسا في السجلّ**.
        //
        // وهذا يكشف الجذرَ الحقيقيَّ لتناقض الرقمين: **لغتان لا واحدة**
        // — ٧٩ عَلَمَ ميزةٍ مقابل ٧١ قدرةً في السجلّ، **وعشرون عَلَماً
        // بلا قدرةٍ تقابله**. فـ«مزايا باقتي» تعدّ السجلّ، و«خدمات
        // التاجر» تسأل الأعلام.
        //
        // **فيُقاس كلٌّ بعقده**: `access.has()` تقرأ الأعلامَ، فبها
        // تُطابَق القائمة. (وتوحيدُ اللغتين قرارٌ بنيويٌّ أكبرُ من هذا
        // الحارس، ويُقال ولا يُسكَت عنه.)
        // ══════════════════════════════════════════════════════════
        $reflection = new \ReflectionClass(AccessConstants::class);
        $known = array_values(array_filter(
            $reflection->getConstants(),
            fn ($v, $k) => str_starts_with($k, 'F_') && is_string($v),
            ARRAY_FILTER_USE_BOTH));

        $orphans = array_values(array_diff($codes, $known));

        $this->assertSame([], $orphans,
            "رموزٌ في «خدمات التاجر» لا يعرفها أعلامُ الميزات:\n  "
            .implode("\n  ", $orphans)."\n\n"
            .'وبلاطةٌ برمزٍ لا يعرفه الخادمُ **لا تُفتَح أبداً**: '
            .'`access.has()` تردّ `false` دائماً، فتبقى في قسم «متاح '
            .'بترقية الباقة» مهما رقّى صاحبُها — وعدٌ لا يُوفى، ولا خطأَ '
            .'في أيّ سجلّ.');
    }

    /** @test */
    public function the_two_screens_no_longer_call_two_different_things_the_same_name(): void
    {
        $src = (string) file_get_contents(base_path(self::HUB));

        // **التناقضُ الذي رآه صاحبُ المشروع**: «٢٩ من ٢٩ خدمة» بجوار
        // «٤٩ متاحة» — ولا شيءَ يقول إنّهما يعدّان شيئين.
        $this->assertStringNotContainsString('من $total خدمة', $src,
            'ما زال العدّادُ يقول «خدمة» عمّا يعدّه، و«مزايا باقتي» تقول '
            .'«متاحة» عن قائمةٍ أخرى — فيبقى الرقمان يتناقضان في وجه '
            .'قارئهما');

        $this->assertStringContainsString('شاشة في التطبيق', $src,
            'العدّادُ لا يسمّي ما يعدّه');

        $this->assertStringContainsString('مزايا باقتي', $src,
            'قيل إنّ هذه الشاشاتُ ولم يُقَل أين القائمةُ الكاملة — '
            .'فيبقى القارئُ يبحث عن الرقم الآخر');
    }

    /** @test */
    public function the_top_plan_is_never_invited_to_upgrade(): void
    {
        // **وعدٌ لا يستطيع النظامُ الوفاءَ به** — والملفُّ نفسُه يقول ذلك
        // في قسم المقفلات، والقاعدةُ نُسيت في زرّ الترويسة.
        $src = (string) file_get_contents(base_path(self::HUB));

        $this->assertStringContainsString('if (!isTopPlan)', $src,
            'زرُّ «ترقية» يُعرَض لصاحب أعلى باقة — يضغطه فيجد باقتَه هي '
            .'العليا، أو يرقّي فلا يُفتَح شيء');

        $this->assertStringContainsString('isTopPlan: access.isEnterprisePlan', $src,
            'الرايةُ مُمرَّرةٌ من مصدرٍ غير الباقة الفعليّة');
    }
}
