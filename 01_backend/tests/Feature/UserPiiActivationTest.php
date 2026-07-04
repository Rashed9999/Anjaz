<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EncryptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-PII-ENCRYPTION-001 — تفعيل تشفير PII على نموذج User.
 */
class UserPiiActivationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function saving_a_user_populates_encrypted_blind_and_masked_columns(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '967771222333', 'email' => 'a@b.com']);

        $row = DB::table('users')->where('id', $user->id)->first();
        // الأعمدة المشفّرة مُملوءة
        $this->assertNotEmpty($row->phone_encrypted);
        $this->assertNotEmpty($row->phone_blind_index);
        $this->assertNotEmpty($row->phone_masked);
        // العمود الصريح يبقى (v1.3 — للتراجع والبحث)
        $this->assertSame('967771222333', $row->phone);
    }

    /** @test */
    public function reading_pii_returns_the_original_value(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '967771222444']);
        $fresh = User::find($user->id);
        $this->assertSame('967771222444', $fresh->phone);
    }

    /** @test */
    public function user_is_findable_by_blind_index_without_decryption(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '967771222555']);

        $svc = app(EncryptionService::class);
        $found = User::where('phone_blind_index', $svc->blindIndex('967771222555', 'phone'))->first();

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
    }

    /** @test AMIAL-PII: الأرقام المختلفة → فهارس عمياء مختلفة (لا تصادم) */
    public function different_phones_get_different_blind_indexes(): void
    {
        $a = User::factory()->create(['type' => 2, 'phone' => '967771000010']);
        $b = User::factory()->create(['type' => 2, 'phone' => '967771000020']);

        $this->assertNotSame(
            DB::table('users')->where('id', $a->id)->value('phone_blind_index'),
            DB::table('users')->where('id', $b->id)->value('phone_blind_index'),
        );
    }
}
