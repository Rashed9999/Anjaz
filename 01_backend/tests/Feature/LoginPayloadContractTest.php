<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-CRASH-003 — العقد بين استجابة الدخول وما يقرؤه التطبيق منها.
 *
 * الصنف الذي يمنعه هذا الملفّ صنفٌ صامت تماماً: التطبيق يقرأ مفتاحاً لا
 * يرسله الخادم، فيحصل على null، ويمضي. لا خطأ، ولا سجلّ، ولا شاشة حمراء —
 * تعطُّلٌ في وضح النهار بلا أيّ أثر.
 *
 * وقد وقع فعلاً: كُتب ربطُ تقارير الأعطال ليقرأ meta.user.unique_id
 * وmeta.user.zone_code، وليس في الاستجابة أيٌّ منهما. ولولا فحص الاستجابة
 * نفسها لبقيت كل التقارير بلا هوية إلى الأبد، ولظُنّ أن التبليغ يعمل.
 *
 * فحصُ الطرفين كلٌّ على حدة لا يكفي: الخادم يمرّ، والتطبيق يمرّ، والعطل
 * بينهما. هذا الملفّ يفحص ما بينهما.
 */
class LoginPayloadContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('passport:install', ['--no-interaction' => true]);
    }

    private function loginCustomer(array $overrides = []): array
    {
        User::factory()->create(array_merge([
            'type' => 2, 'role' => 'customer', 'zone_code' => 'SOUTH',
            'phone' => '967771230055', 'password' => Hash::make('1234'),
            'is_active' => 1,
        ], $overrides));

        return $this->postJson('/api/v1/auth/login', [
            'role' => 'customer', 'phone' => '967771230055', 'password' => '1234',
        ])->assertStatus(200)->json();
    }

    /**
     * الحقول التي يقرؤها التطبيق من meta.user — كل واحد منها له مستهلك.
     */
    public function test_login_returns_every_field_the_app_reads(): void
    {
        $body = $this->loginCustomer();

        foreach (['id', 'role', 'name', 'verification_state', 'zone_code'] as $key) {
            $this->assertArrayHasKey($key, $body['meta']['user'],
                "التطبيق يقرأ meta.user.$key والخادم لا يرسله — يقرأ null صامتاً");
        }
    }

    /** المعرّف الذي تُربط به تقارير الأعطال — بدونه كل التقارير «ضيف». */
    public function test_the_user_id_is_present_and_not_empty(): void
    {
        $body = $this->loginCustomer();

        $this->assertNotNull($body['meta']['user']['id']);
        $this->assertNotSame('', (string) $body['meta']['user']['id']);
    }

    /** المنطقة تفرّق عطل الإعداد من عطل الشيفرة، فيجب أن تصل كما هي. */
    public function test_zone_code_matches_the_account(): void
    {
        $body = $this->loginCustomer(['zone_code' => 'NORTH']);

        $this->assertSame('NORTH', $body['meta']['user']['zone_code']);
    }

    /**
     * لا وجود لحساب بلا منطقة — العمود NOT NULL في المخطّط.
     *
     * كُتب هذا الاختبار أوّلاً على فرض أن المنطقة قد تغيب فيصل null، فأسقطه
     * المخطّط. والنتيجة أقوى من الفرض: المنطقة مضمونة في كل تقرير عطل، فلا
     * حاجة إلى مسار احتياطي في التطبيق. ويبقى الاختبار حارساً على الضمانة —
     * فمن يجعل العمود قابلاً للإفراغ لاحقاً يسقط هنا.
     */
    public function test_no_account_can_exist_without_a_zone(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create([
            'type' => 2, 'role' => 'customer', 'zone_code' => null,
            'phone' => '967771230077', 'password' => Hash::make('1234'),
        ]);
    }

    /**
     * ولا يتسرّب ما لا يُحتاج: الاستجابة ليست مكاناً لكلمة المرور المُعمّاة
     * ولا لرمز المعاملات ولا للرصيد.
     */
    public function test_the_payload_carries_no_secret(): void
    {
        $body = $this->loginCustomer();
        $user = $body['meta']['user'];

        foreach (['password', 'transaction_pin', 'pin', 'remember_token'] as $secret) {
            $this->assertArrayNotHasKey($secret, $user, "تسرّب $secret في استجابة الدخول");
        }
    }
}
