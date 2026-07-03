<?php

namespace Tests\Feature;

use App\Services\EncryptionService;
use RuntimeException;
use Tests\TestCase;

/**
 * AMIAL-PII-001 — تشفير البيانات الحساسة (امتثال حماية البيانات).
 *
 * يثبت أنّ محرّك التشفير (AES-256-GCM + blind index HMAC) يفي بمتطلّبات
 * حماية PII للبنوك:
 *   - سرّية: النصّ المُشفَّر لا يكشف الأصل، ومختلف كلّ مرّة (IV عشوائي)
 *   - سلامة: العبث بالنصّ المُشفَّر يُكتشَف (GCM tag) ولا يُعيد نصّاً مشوّهاً
 *   - قابلية البحث بلا فكّ: blind index حتمي وغير عكسي وطبيعيّ (هاتف/بريد)
 */
class PiiEncryptionTest extends TestCase
{
    private EncryptionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(EncryptionService::class);
    }

    /** @test */
    public function round_trips_all_kinds_of_values(): void
    {
        $values = [
            '+967771234567', 'user@example.com', 'محمد عبدالله الأحمدي',
            '01/1234567', 'A1B2-C3D4-E5F6', str_repeat('x', 2000),
            'رقم وطني ٠١٢٣٤٥', "line1\nline2\ttab", '💳🔐 محفظة', 'O\'Brien; DROP',
        ];
        foreach ($values as $v) {
            $ct = $this->svc->encrypt($v);
            $this->assertNotSame($v, $ct, 'النصّ المُشفَّر يساوي الأصل!');
            $this->assertTrue($this->svc->isEncrypted($ct));
            $this->assertStringNotContainsString($v, (string) $ct, 'الأصل ظاهر في النصّ المُشفَّر!');
            $this->assertSame($v, $this->svc->decrypt($ct), "round-trip فشل لـ: {$v}");
        }
    }

    /** @test */
    public function same_plaintext_encrypts_differently_each_time_but_decrypts_equal(): void
    {
        $p = '+967771234567';
        $a = $this->svc->encrypt($p);
        $b = $this->svc->encrypt($p);
        // IV عشوائي → نصّان مختلفان (لا تسريب تساوي القيم عبر النصّ المُشفَّر)
        $this->assertNotSame($a, $b, 'النصّ المُشفَّر متطابق — IV غير عشوائي!');
        $this->assertSame($p, $this->svc->decrypt($a));
        $this->assertSame($p, $this->svc->decrypt($b));
    }

    /** @test */
    public function tampering_is_detected_never_returns_corrupted_plaintext(): void
    {
        $ct = $this->svc->encrypt('+967771234567');

        // اقلب حرفاً في منتصف النصّ المُشفَّر
        $mid = intdiv(strlen($ct), 2);
        $orig = $ct[$mid];
        $ct[$mid] = $orig === 'A' ? 'B' : 'A';

        // decrypt يجب أن يرمي (GCM tag لا يطابق)
        $threw = false;
        try {
            $this->svc->decrypt($ct);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'العبث لم يُكتشَف — decrypt نجح على نصّ معبوث!');
        // tryDecrypt يعيد null لا نصّاً مشوّهاً
        $this->assertNull($this->svc->tryDecrypt($ct));
    }

    /** @test */
    public function unsupported_version_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->decrypt('rawbase64withoutversionprefix==');
    }

    /** @test */
    public function blind_index_is_deterministic_and_collision_distinct(): void
    {
        $i1 = $this->svc->blindIndex('+967771234567', 'phone');
        $i2 = $this->svc->blindIndex('+967771234567', 'phone');
        $this->assertSame($i1, $i2, 'blind index غير حتمي!');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $i1, 'ليس HMAC-SHA256');

        // قيمة مختلفة → index مختلف
        $this->assertNotSame($i1, $this->svc->blindIndex('+967779999999', 'phone'));
        // غير عكسي: لا يحوي الأصل
        $this->assertStringNotContainsString('771234567', $i1);
    }

    /** @test */
    public function blind_index_normalizes_phone_and_email(): void
    {
        // الهاتف: تُزال المسافات/الشرطات (يبقى + دلالياً) قبل الفهرسة
        $this->assertSame(
            $this->svc->blindIndex('+967 77-123 4567', 'phone'),
            $this->svc->blindIndex('+967771234567', 'phone'),
            'تطبيع الهاتف لا يوحّد المسافات/الشرطات'
        );
        // الرقم الوطني: تُزال كل الرموز غير الرقمية
        $this->assertSame(
            $this->svc->blindIndex('01/123-4567', 'national_id'),
            $this->svc->blindIndex('011234567', 'national_id'),
            'تطبيع الرقم الوطني لا يوحّد الصيغ'
        );
        // البريد: غير حسّاس لحالة الأحرف
        $this->assertSame(
            $this->svc->blindIndex('User@Example.COM', 'email'),
            $this->svc->blindIndex('user@example.com', 'email'),
            'تطبيع البريد لا يوحّد الحالة'
        );
    }

    /** @test */
    public function null_and_empty_are_handled_safely(): void
    {
        $this->assertNull($this->svc->encrypt(null));
        $this->assertNull($this->svc->encrypt(''));
        $this->assertNull($this->svc->decrypt(null));
        $this->assertNull($this->svc->blindIndex(null, 'phone'));
    }
}
