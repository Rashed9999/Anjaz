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
            'type' => 2,                 // 0=admin, 1=merchant, 2=customer
            'role' => 'customer',
            'verification_level' => 'basic',
            // الاختبارات المالية تنشئ عميلاً صالحاً افتراضياً؛ الاختبارات
            // التي تريد رفض KYC تصرّح بالمستوى الأقل صراحةً.
            'kyc_tier' => 2,
            'is_active' => true,
            'zone_code' => 'SOUTH',      // المنطقة المسموح لها افتراضياً
            'unique_id' => (string) Str::uuid(),
            'remember_token' => Str::random(10),
        ];
    }

    /** مستخدم أدمن (type=0). */
    public function admin(): static
    {
        return $this->state(fn () => ['type' => 0, 'role' => 'super_admin']);
    }

    /** مستخدم تاجر (type=1). */
    public function merchant(): static
    {
        return $this->state(fn () => ['type' => 1, 'role' => 'merchant']);
    }

    /** خارج منطقة الجنوب (لاختبار سياسة الـ Zone). */
    public function outsideZone(string $zone = 'NORTH'): static
    {
        return $this->state(fn () => ['zone_code' => $zone]);
    }
}
