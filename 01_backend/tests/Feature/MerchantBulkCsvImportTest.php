<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MerchantBulkCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $u = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        app(VerticalBootstrapService::class)->ensureFor($u);

        return $u;
    }

    /** @test */
    public function retrying_the_same_product_csv_does_not_duplicate_successful_rows(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'merchant_web');

        $csv = implode("\n", [
            'name,price,cost_price,quantity,barcode,sku,track_stock',
            'ماء 500مل,100,70,12,6291000000011,WATER-1,1',
            'عصير تفاح,250,180,8,6291000000028,JUICE-1,1',
        ])."\n";

        $first = UploadedFile::fake()->createWithContent('products.csv', $csv);
        $this->post('/merchant/data/sector/products/import', ['file' => $first], [
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('meta.added', 2)
            ->assertJsonPath('meta.already_done', 0)
            ->assertJsonPath('meta.skipped', 0);

        $this->assertSame(2, MerchantProduct::where('merchant_user_id', $owner->id)->count());

        $retry = UploadedFile::fake()->createWithContent('products.csv', $csv);
        $this->post('/merchant/data/sector/products/import', ['file' => $retry], [
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('meta.added', 0)
            ->assertJsonPath('meta.already_done', 2)
            ->assertJsonPath('meta.skipped', 0);

        $this->assertSame(2, MerchantProduct::where('merchant_user_id', $owner->id)->count());
        $this->assertDatabaseCount('merchant_bulk_import_rows', 2);
    }

    /** @test */
    public function oversized_csv_is_rejected_before_any_product_is_created(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'merchant_web');

        $lines = ['name,price,sku'];
        for ($i = 1; $i <= 501; $i++) {
            $lines[] = "منتج {$i},100,SKU-{$i}";
        }

        $file = UploadedFile::fake()->createWithContent(
            'too-many-products.csv',
            implode("\n", $lines)."\n",
        );

        $this->post('/merchant/data/sector/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'CSV_TOO_MANY_ROWS');

        $this->assertSame(0, MerchantProduct::where('merchant_user_id', $owner->id)->count());
        $this->assertDatabaseCount('merchant_bulk_import_rows', 0);
    }
}
