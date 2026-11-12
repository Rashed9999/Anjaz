<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * موثّق يسأل أميال باي قبل أن يقبل طلب فتح الحساب: هاتف · بريد · رقم هوية. وجودٌ فقط، لا بيانات أحد.
 * الجواب يجب أن يطابق ما سيرفضه التزويد لاحقاً، وإلا تعود قضيةٌ معتمدة تُرفض بـ422 بعد أن فرغ المراجع منها.
 */
class MuwathaqIdentityCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['muwathaq.decision_secret' => 'dec-secret', 'muwathaq.transport_secret' => null]);
    }

    private function ask(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = (string) time();

        return $this->call('POST', '/api/v1/internal/muwathaq/identity-check', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_MUWATHAQ_TIMESTAMP' => $ts,
            'HTTP_X_MUWATHAQ_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, 'dec-secret'),
        ], $body);
    }

    #[Test]
    public function an_unsigned_call_is_refused(): void
    {
        $this->postJson('/api/v1/internal/muwathaq/identity-check', ['phone' => '967771234567'])->assertStatus(401);
    }

    #[Test]
    public function a_known_phone_and_email_are_reported_and_a_stranger_is_not(): void
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771234567', 'email' => 'Known@Example.test']);

        $hit = $this->ask(['phone' => '+967 77 123 4567', 'email' => 'known@example.test'])->assertOk()->json('data');
        $this->assertTrue($hit['phone_exists'], 'الهاتف بصيغةٍ مختلفة يُطابق');
        $this->assertTrue($hit['email_exists'], 'البريد بحالة أحرفٍ مختلفة يُطابق');

        $miss = $this->ask(['phone' => '967779999999', 'email' => 'nobody@example.test'])->assertOk()->json('data');
        $this->assertFalse($miss['phone_exists']);
        $this->assertFalse($miss['email_exists']);

        $this->assertNotNull($u);
    }

    #[Test]
    public function an_id_number_reports_only_the_kind_of_account_that_holds_it(): void
    {
        $u = User::factory()->create(['type' => MERCHANT_TYPE, 'is_active' => 1]);
        $u->identification_number = '222222555';
        $u->save();

        $r = $this->ask(['identification_number' => '٢٢٢٢٢٢٥٥٥'])->assertOk()->json('data');
        $this->assertTrue($r['identification_checked']);
        $this->assertSame(['merchant'], $r['identification_kinds'], 'النوع فقط — لا اسم ولا معرّف');

        $none = $this->ask(['identification_number' => '999999999'])->assertOk()->json('data');
        $this->assertSame([], $none['identification_kinds']);
    }

    #[Test]
    public function a_too_short_id_number_is_reported_as_not_checked_not_as_clear(): void
    {
        $r = $this->ask(['identification_number' => '12'])->assertOk()->json('data');
        $this->assertFalse($r['identification_checked'], '«لم يُفحص» ليس «سليم»');
    }
}
