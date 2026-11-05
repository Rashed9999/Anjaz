<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * UserFactory — كان مفقوداً (انظر FIXES.md) رغم اعتماد عشرات الاختبارات على
 * `User::factory()`. أُعيد بناؤه من حقول النموذج (casts/fillable) ومن الهجرات.
 *
 * ملاحظة دمج: إن أضافت قاعدة Cash6 الأصلية أعمدة NOT NULL إضافية بلا قيمة
 * افتراضية، أضِفها هنا لتفادي أخطاء الإدراج.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'f_name' => $this->faker->firstName(),
            'l_name' => $this->faker->lastName(),
            'dial_country_code' => '+967',
            'phone' => '+9677' . $this->faker->unique()->numberBetween(10000000, 99999999),
            'email' => $this->faker->unique()->safeEmail(),
            'image' => 'def.png',
            'password' => static::$password ??= Hash::make('password'),
            'is_phone_verified' => 1,
            'is_email_verified' => 1,
            'is_kyc_verified' => 1,
            'type' => 2,                 // 0=admin, 1=agent, 2=customer, 3=merchant
            'role' => 'customer',
            'verification_level' => 'basic',
            // المصنع الافتراضي = عميل مالي صالح فعلاً، لا نصف حالة.
            // اختبارات Tier 0 / pending / rejected تستخدم الحالات الصريحة أدناه.
            'kyc_tier' => 2,
            'is_active' => true,
            'zone_code' => 'SOUTH',
            // AMIAL-RESIDENCE-TEST-001 — SOUTH وحده لم يعد دليلاً. المصنع
            // المالي الافتراضي يمثل عميلاً ذا إقامة موثقة في عدن، كي تختبر
            // الخدمات المالية موضوعها الحقيقي بدلاً من أن تسقط عند بوابة
            // الإقامة. اختبارات الغياب/الخروج تصرّح بذلك صراحةً.
            'residence_governorate' => 'YE-AD',
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
            'unique_id' => (string) Str::uuid(),
            'remember_token' => Str::random(10),
        ];
    }


    /** عميل جديد حقيقي: يدخل التطبيق لكن قدرته المالية = صفر. */
    public function tierZero(): static
    {
        return $this->state(fn () => [
            'is_kyc_verified' => 0,
            'kyc_tier' => 0,
            'is_phone_verified' => 0,
            'residence_governorate' => null,
            'verified_residence_governorate' => null,
            'residence_verified_at' => null,
            'zone_code' => 'UNKNOWN',
        ]);
    }

    /** عميل بلا إقامة موثقة — لاختبارات KYC/Zone التي تختبر الغياب نفسه. */
    public function withoutVerifiedResidence(): static
    {
        return $this->state(fn () => [
            'verified_residence_governorate' => null,
            'residence_verified_at' => null,
        ]);
    }

    /** مستخدم أدمن (type=0). */
    public function admin(): static
    {
        return $this->state(fn () => ['type' => 0, 'role' => 'super_admin']);
    }

    /** مستخدم تاجر حقيقي وفق app/Lib/Constant.php (MERCHANT_TYPE = 3). */
    public function merchant(): static
    {
        return $this->state(fn () => ['type' => 3, 'role' => 'merchant']);
    }

    /** خارج منطقة التشغيل (لاختبار سياسة الـ Zone). */
    public function outsideZone(string $zone = 'NORTH'): static
    {
        return $this->state(fn () => [
            'zone_code' => $zone,
            'residence_governorate' => 'YE-SN',
            'verified_residence_governorate' => 'YE-SN',
            'residence_verified_at' => now(),
        ]);
    }
}
