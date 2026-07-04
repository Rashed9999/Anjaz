<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EncryptedFileStorage;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-SIGNATURE-001 — التوقيع الإلكتروني عند فتح الحساب.
 */
class SignatureRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // أطفئ التحقّق بالOTP للتسجيل الآلي
        try { DB::table('business_settings')->updateOrInsert(['key' => 'phone_verification'], ['value' => 0]); } catch (\Throwable $e) {}
    }

    private function pngDataUri(): string
    {
        $img = imagecreatetruecolor(200, 80);
        imagestring($img, 5, 10, 30, 'Sig', imagecolorallocate($img, 0, 0, 0));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function register(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/customer/auth/register', array_merge([
            'f_name' => 'أحمد', 'l_name' => 'سالم', 'gender' => 'male',
            'dial_country_code' => '+967', 'phone' => '771555001', 'password' => '1234',
        ], $extra));
    }

    /** @test */
    public function registration_stores_signature_encrypted(): void
    {
        $this->register(['signature' => $this->pngDataUri()])->assertStatus(200);

        $user = User::whereIn('phone', Phone::variants('771555001'))->first();
        $this->assertNotNull($user->signature_encrypted_path);
        $this->assertNotNull($user->signature_captured_at);

        // الملفّ المشفّر موجود ويفكّ لصورة PNG صحيحة (سجلّ قانوني قابل للاسترجاع)
        $svc = app(EncryptedFileStorage::class);
        $this->assertTrue($svc->exists($user->signature_encrypted_path));
        $binary = $svc->decryptToBinary($user->signature_encrypted_path);
        $info = getimagesizefromstring($binary);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
    }

    /** @test AMIAL-SIGNATURE: التوقيع اختياري — التسجيل بدونه يعمل (توافق خلفي) */
    public function registration_without_signature_still_works(): void
    {
        $this->register()->assertStatus(200);
        $user = User::whereIn('phone', Phone::variants('771555001'))->first();
        $this->assertNull($user->signature_encrypted_path);
    }

    /** @test AMIAL-SIGNATURE: مدخل غير صورة لا يُخزَّن ولا يكسر التسجيل */
    public function invalid_signature_is_ignored_not_stored(): void
    {
        $this->register(['signature' => 'not-a-real-image-just-text'])->assertStatus(200);
        $user = User::whereIn('phone', Phone::variants('771555001'))->first();
        $this->assertNull($user->signature_encrypted_path);
    }
}
