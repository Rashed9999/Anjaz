<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants as A;
use Database\Seeders\MerchantDemoMatrixSeeder;
use Tests\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════
 * **مصفوفةُ حساباتِ العرض — والفهرسُ يُقرأ من مصدره.**
 *
 * كان مكتوباً هنا **ثلاثون** صفّاً وخمسُ باقاتٍ بأسمائها، فلمّا وحّد
 * صاحبُ المشروع الباقاتِ في ثلاثٍ صارت المصفوفةُ ثمانيةَ عشرَ صفّاً
 * **وسقطت ثلاثةُ اختباراتٍ على قرارِ تسعيرٍ سليم**.
 *
 * ورقمٌ مكتوبٌ في حارسٍ يشيخ مع أوّل قرار، **ثمّ يمنع القرارَ الصحيح**
 * بدل أن يحرس شيئاً. فالعددُ يُشتقّ: أصنافُ النشاط × الباقاتِ المُباعة.
 */
class MerchantDemoMatrixTest extends TestCase
{
    /** كم صفّاً يجب أن تكون المصفوفة — محسوباً لا مكتوباً. */
    private function expectedCount(): int
    {
        return count(A::ALL_BUSINESS_TYPES) * count(A::ALL_PLANS);
    }

    /** @test */
    public function demo_matrix_contains_every_business_type_and_plan_exactly_once(): void
    {
        $rows = MerchantDemoMatrixSeeder::accounts();

        $this->assertCount($this->expectedCount(), $rows);

        foreach (A::ALL_BUSINESS_TYPES as $businessType) {
            foreach (A::ALL_PLANS as $plan) {
                $matches = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => $row['business_type'] === $businessType
                        && $row['plan'] === $plan,
                ));

                $this->assertCount(
                    1,
                    $matches,
                    "Missing/duplicated demo merchant for {$businessType} × {$plan}",
                );
            }
        }

        // **ولا صفَّ بباقةٍ لا تُباع** — حسابُ عرضٍ على باقةٍ ملغاةٍ يفتح
        // شاشاتٍ لا يبلغها زبونٌ حقيقيّ، فيُجرَّب ما لا يُشترى.
        foreach ($rows as $row) {
            $this->assertContains($row['plan'], A::ALL_PLANS,
                "حسابُ عرضٍ على باقة «{$row['plan']}» وليست في الفهرس المُباع");
        }
    }

    /** @test */
    public function demo_matrix_phones_are_unique_and_follow_the_documented_pattern(): void
    {
        $rows = MerchantDemoMatrixSeeder::accounts();
        $phones = array_column($rows, 'phone');
        $local = array_column($rows, 'phone_local');

        $this->assertCount($this->expectedCount(), array_unique($phones));
        $this->assertCount($this->expectedCount(), array_unique($local));

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^96777721[1-6]00[0-4]$/', (string) $row['phone']);
            $this->assertMatchesRegularExpression('/^77721[1-6]00[0-4]$/', (string) $row['phone_local']);
            $this->assertSame('967' . $row['phone_local'], $row['phone']);
        }
    }

    /**
     * **وأرقامُ الجملة تُحفظ عن ظهر قلب** — يكتبها صاحبُ المشروع في
     * الهاتف بلا رجوعٍ إلى ورقة.
     *
     * ولذلك **بقيت خانتُها الأخيرة كما كانت** حين أُلغيت باقتان: مجّانيّةٌ
     * على `…000`، والأعمالُ على `…002`، ومؤسسةٌ على `…004`. أي أنّ من
     * حفظ رقماً لم يفقده، وسقط `…001` و`…003` وحدَهما.
     */
    /** @test */
    public function wholesale_accounts_keep_their_memorable_numbers_after_the_merge(): void
    {
        $rows = array_values(array_filter(
            MerchantDemoMatrixSeeder::accounts(),
            fn (array $row): bool => $row['business_type'] === A::BIZ_WHOLESALE,
        ));

        $this->assertSame(A::ALL_PLANS, array_column($rows, 'plan'));

        $this->assertSame(
            ['777215000', '777215002', '777215004'],
            array_column($rows, 'phone_local'),
            'تحرّك رقمُ حسابِ عرضٍ محفوظ — فمن حفظه يدخل على باقةٍ غيرِ التي يقصد');
    }

    /** @test */
    public function credentials_and_demo_balance_are_consistent_for_repeatable_manual_testing(): void
    {
        foreach (MerchantDemoMatrixSeeder::accounts() as $row) {
            $this->assertSame(MerchantDemoMatrixSeeder::PASSWORD, $row['password']);
            $this->assertSame(MerchantDemoMatrixSeeder::PIN, $row['pin']);
            $this->assertSame(MerchantDemoMatrixSeeder::BALANCE_YER, $row['balance_yer']);
        }
    }
}
