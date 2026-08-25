<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants as A;
use Database\Seeders\MerchantDemoMatrixSeeder;
use Tests\TestCase;

class MerchantDemoMatrixTest extends TestCase
{
    /** @test */
    public function demo_matrix_contains_every_business_type_and_plan_exactly_once(): void
    {
        $rows = MerchantDemoMatrixSeeder::accounts();

        $this->assertCount(30, $rows);

        $businessTypes = [
            A::BIZ_QUICK_SALE,
            A::BIZ_RETAIL,
            A::BIZ_FUEL,
            A::BIZ_PHARMACY,
            A::BIZ_WHOLESALE,
            A::BIZ_RESTAURANT,
        ];
        $plans = [
            A::PLAN_FREE,
            A::PLAN_STARTER,
            A::PLAN_BUSINESS,
            A::PLAN_MERCHANT_PRO,
            A::PLAN_ENTERPRISE,
        ];

        foreach ($businessTypes as $businessType) {
            foreach ($plans as $plan) {
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
    }

    /** @test */
    public function demo_matrix_phones_are_unique_and_follow_the_documented_pattern(): void
    {
        $rows = MerchantDemoMatrixSeeder::accounts();
        $phones = array_column($rows, 'phone');
        $local = array_column($rows, 'phone_local');

        $this->assertCount(30, array_unique($phones));
        $this->assertCount(30, array_unique($local));

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^96777721[1-6]00[0-4]$/', (string) $row['phone']);
            $this->assertMatchesRegularExpression('/^77721[1-6]00[0-4]$/', (string) $row['phone_local']);
            $this->assertSame('967' . $row['phone_local'], $row['phone']);
        }
    }

    /** @test */
    public function wholesale_accounts_are_the_memorable_777215000_to_777215004_range(): void
    {
        $rows = array_values(array_filter(
            MerchantDemoMatrixSeeder::accounts(),
            fn (array $row): bool => $row['business_type'] === A::BIZ_WHOLESALE,
        ));

        $this->assertSame(
            ['777215000', '777215001', '777215002', '777215003', '777215004'],
            array_column($rows, 'phone_local'),
        );
        $this->assertSame(
            [A::PLAN_FREE, A::PLAN_STARTER, A::PLAN_BUSINESS, A::PLAN_MERCHANT_PRO, A::PLAN_ENTERPRISE],
            array_column($rows, 'plan'),
        );
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
