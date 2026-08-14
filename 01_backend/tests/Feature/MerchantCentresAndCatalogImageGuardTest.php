<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Catalog\CatalogImageService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-SIDEBAR-SPLIT-001 · AMIAL-MERCHANT-360-DRILL-001 · AMIAL-CATALOG-IMAGE-001
 *
 * ══════════════════════════════════════════════════════════════════════
 * سؤالان سألهما صاحبُ المشروع، وكلٌّ منهما كشف عطلاً غيرَ الذي سُئل عنه.
 *
 *  ① «لماذا يجب أن تكون كلُّ هذه القوائم؟ المتّفق سابقاً للإدارة هو
 *     رؤية الأمور المالية»
 *
 *     وقياسُ الثلاثة أثبت أنّها **ليست ERP بل رقابةُ احتيال**، وأنّ
 *     طيَّها في ملفّ تاجرٍ يُتلف غرضَها. **والعطلُ الحقيقيّ كان غيرَه:**
 *     ملفُّ التاجر ٣٦٠ بلا رابطٍ واحدٍ إلى أيٍّ منها — فمن يحقّق في
 *     تاجرٍ يعود إلى القائمة ويبحث عن اسمه في كلّ مركزٍ من جديد. وهذا
 *     ما يجعل القوائمَ تبدو كثيرةً، لا عددُها.
 *
 *  ② «لنفترض مليون منتج، أين تُحفظ الصور؟ ألن يُخرِّب السيرفر؟»
 *
 *     والتصميمُ يمنعه أصلاً: `merchant_products` بلا عمود صورة،
 *     والصورةُ واحدةٌ لكلّ **باركود** يشترك فيها كلُّ التجّار.
 *
 *     **لكنّ `Helpers::upload` كانت ستُخرِّبه فعلاً**: سقفُها ٢٥٠٠
 *     بكسل، و**webp تُنسخ بلا تصغيرٍ إطلاقاً**. فمئةُ ألف باركود ×
 *     ٥٠٠ ك.ب = ٥٠ غيغابايت على قرص الخادم، تنسخها كلُّ نسخةٍ
 *     احتياطيّة. فبُنيت `CatalogImageService` بسقف ٤٠٠ بكسل.
 * ══════════════════════════════════════════════════════════════════════
 */
class MerchantCentresAndCatalogImageGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($this->admin, PlatformRoleService::ADMIN);
    }

    private function sidebar(): string
    {
        return file_get_contents(
            base_path('resources/views/admin-views/amial/partials/_sidebar.blade.php'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① القائمة: فُصلت ولم تُحذف
    // ══════════════════════════════════════════════════════════════════

    public function test_the_merchant_group_is_the_five_that_were_agreed(): void
    {
        $sidebar = $this->sidebar();

        // «المتّفق سابقاً» — الحسابات والمال والباقات والكتالوج.
        foreach (['admin.amial.hub.merchants', 'admin.amial.hub.subscriptions',
                  'admin.amial.entitlements.page', 'admin.amial.invoices.page',
                  'admin.amial.catalog.page'] as $route) {
            $this->assertStringContainsString($route, $sidebar, "رابطٌ مفقود: {$route}");
        }
    }

    public function test_no_surveillance_centre_was_lost_in_the_split(): void
    {
        $sidebar = $this->sidebar();

        // **فُصلت ولم تُحذف** — وحذفُها يُفقد المنصّةَ عينَها على الاحتيال.
        // (القاعدة ١٢: صفحةٌ لا يُوصل إليها ليست مبنيّة.)
        foreach (['admin.amial.hub.staff', 'admin.amial.fuel.page',
                  'admin.amial.retail.page'] as $route) {
            $this->assertStringContainsString($route, $sidebar, "مركزُ رقابةٍ سقط: {$route}");
        }

        $this->assertStringContainsString('رقابة عمل التجّار', $sidebar,
            'الثلاثةُ باقيةٌ ولا مجموعةَ تجمعها — فالشكوى قائمة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الملفّ ٣٦٠ يقود إلى المراكز — العطلُ الذي لم يُشتكَ منه
    // ══════════════════════════════════════════════════════════════════

    public function test_the_merchant_file_links_into_each_surveillance_centre(): void
    {
        $html = file_get_contents(
            base_path('resources/views/admin-views/amial/hub/account.blade.php'));

        foreach (['hub/staff', 'admin/amial/retail', 'admin/amial/fuel'] as $needle) {
            $this->assertStringContainsString($needle, $html,
                "ملفُّ التاجر بلا رابطٍ إلى: {$needle}");
        }

        // **ورابطٌ بلا مُعرِّفِ التاجر يفتح قائمةَ الجميع** — يعمل ويصل
        // إلى الشاشة الخطأ، وهو أخطرُ من زرٍّ لا يعمل (القاعدة ٩).
        $this->assertStringContainsString("?merchant=' + id", $html,
            'الروابطُ لا تحمل مُعرِّفَ التاجر');
    }

    public function test_each_centre_actually_honours_the_merchant_filter(): void
    {
        $merchant = User::factory()->create(['type' => MERCHANT_TYPE, 'phone' => '967771950001']);
        $other = User::factory()->create(['type' => MERCHANT_TYPE, 'phone' => '967771950002']);

        $u1 = User::factory()->create(['phone' => '967771950011']);
        $u2 = User::factory()->create(['phone' => '967771950012']);

        DB::table('pos_users')->insert([
            ['user_id' => $u1->id, 'merchant_user_id' => $merchant->id, 'display_name' => 'موظّفُنا',
             'pos_number' => 'P-1', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $u2->id, 'merchant_user_id' => $other->id, 'display_name' => 'موظّفُ غيرِنا',
             'pos_number' => 'P-2', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/staff/list.json?merchant_id=' . $merchant->id)
            ->assertOk()->json('data');

        $names = array_column($rows, 'display_name');
        $this->assertContains('موظّفُنا', $names);
        $this->assertNotContains('موظّفُ غيرِنا', $names,
            'المرشّحُ مُرِّر ولم يُطبَّق — الزرُّ يفتح قائمةَ الجميع');

        // والصفحاتُ الثلاث تمرّر المُعرِّف فعلاً.
        foreach ([['hub/staff.blade.php', 'merchant_id'],
                  ['fuel/index.blade.php', 'merchant_id'],
                  ['retail/index.blade.php', 'merchant_id']] as [$file, $needle]) {
            $path = base_path('resources/views/admin-views/amial/' . $file);
            $this->assertStringContainsString($needle, file_get_contents($path),
                "الصفحةُ لا تمرّر المُعرِّف: {$file}");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ صورةُ الكتالوج — محدودةٌ بحجمها
    // ══════════════════════════════════════════════════════════════════

    public function test_a_catalog_image_is_bounded_so_a_million_products_cannot_fill_the_disk(): void
    {
        $service = app(CatalogImageService::class);

        // صورةٌ ضخمةٌ كالتي يرسلها هاتفٌ حديث.
        $path = $service->store(UploadedFile::fake()->image('big.jpg', 4000, 3000));

        $abs = storage_path('app/public/' . $path);
        $this->assertFileExists($abs);

        [$w, $h] = getimagesize($abs);

        // **السقفُ هو الجواب على سؤال المليون منتج.**
        $this->assertLessThanOrEqual(CatalogImageService::MAX_EDGE, max($w, $h),
            "الصورةُ لم تُصغَّر: {$w}×{$h}");

        // ٤٠٠ بكسل webp ≈ ٢٥ ك.ب. ومئةُ ألف صنفٍ بهذا ≈ ٢٫٥ غيغابايت.
        $bytes = filesize($abs);
        $this->assertLessThan(100 * 1024, $bytes,
            "الصورةُ {$bytes} بايت — والمئةُ ألفِ صنفٍ تصير " . round($bytes * 100000 / 1073741824, 1) . ' غيغابايت');

        Storage::disk('public')->delete($path);
    }

    public function test_a_webp_is_downscaled_too_not_merely_copied(): void
    {
        // **هذا بعينه ما تفعله `Helpers::upload` خطأً**: `copy()` على webp
        // بلا تصغير. فملفُّ أربعةِ ميغابايت يمرّ كما هو — وهو أخطرُ من
        // سقفِ ٢٥٠٠ لأنّه يتخطّى السقفَ نفسَه.
        $service = app(CatalogImageService::class);

        $src = imagecreatetruecolor(2000, 2000);
        $tmp = tempnam(sys_get_temp_dir(), 'wg') . '.webp';
        imagewebp($src, $tmp, 90);
        imagedestroy($src);

        $path = $service->store(new UploadedFile($tmp, 'x.webp', 'image/webp', null, true));

        [$w, $h] = getimagesize(storage_path('app/public/' . $path));
        $this->assertLessThanOrEqual(CatalogImageService::MAX_EDGE, max($w, $h),
            "webp مرّت بلا تصغير: {$w}×{$h}");

        Storage::disk('public')->delete($path);
        @unlink($tmp);
    }

    public function test_a_small_image_is_not_blown_up(): void
    {
        // تكبيرُ صورةِ ١٥٠ بكسل يزيد البايتات ولا يزيد التفصيل.
        $service = app(CatalogImageService::class);
        $path = $service->store(UploadedFile::fake()->image('tiny.png', 150, 150));

        [$w, $h] = getimagesize(storage_path('app/public/' . $path));
        $this->assertSame([150, 150], [$w, $h], 'الصغيرةُ كُبِّرت بلا فائدة');

        Storage::disk('public')->delete($path);
    }

    public function test_the_upload_endpoint_is_reachable_and_writes_the_column(): void
    {
        // `image_path` كان عموداً **يُقرأ في موضعين ولا يُكتب في موضع**.
        $up = $this->actingAs($this->admin, 'user')
            ->post('/admin/amial/catalog/images', [
                'file' => UploadedFile::fake()->image('p.jpg', 900, 900),
            ])->assertOk()->json();

        $this->assertTrue($up['success']);
        $path = $up['image_path'];

        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/catalog', [
                'barcode' => '6281000123456',
                'name' => 'شاي',
                'image_path' => $path,
            ])->assertOk();

        $this->assertSame($path, DB::table('product_catalog_entries')
            ->where('barcode', '6281000123456')->value('image_path'),
            'رُفعت الصورةُ ولم تُربط بالصنف');

        Storage::disk('public')->delete($path);
    }

    public function test_the_upload_refuses_a_file_that_is_not_an_image(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/catalog/images', [
                'file' => UploadedFile::fake()->create('script.svg', 8, 'image/svg+xml'),
            ])->assertStatus(422);
    }

    public function test_the_catalog_panel_offers_the_upload(): void
    {
        // القاعدة ١٢: نقطةٌ مسجَّلةٌ بلا حقلٍ يستعملها ليست ظهوراً.
        $html = file_get_contents(
            base_path('resources/views/admin-views/amial/catalog/index.blade.php'));

        foreach (['data-testid="cat-image-file"', 'type="file"',
                  'catalog.images', 'image_path'] as $needle) {
            $this->assertStringContainsString($needle, $html, "شاشةُ الكتالوج بلا: {$needle}");
        }
    }

    public function test_merchant_products_carry_no_image_column(): void
    {
        // **هذا هو الجوابُ البنيويّ على سؤال المليون منتج**، ولو أُضيف
        // عمودُ صورةٍ هنا يوماً لصارت الصورةُ لكلّ منتجِ تاجرٍ لا لكلّ
        // باركود — فمليونُ منتجٍ يصير مليونَ صورة.
        $this->assertNotContains('image_path',
            \Illuminate\Support\Facades\Schema::getColumnListing('merchant_products'),
            'صورةٌ لكلّ منتجِ تاجر — والباركود هو وحدةُ المشاركة الصحيحة');
    }
}
