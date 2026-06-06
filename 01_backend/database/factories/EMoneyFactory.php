<?php

namespace Database\Factories;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * EMoneyFactory — محفظة المستخدم. مفيدة لإنشاء أرصدة في الاختبارات بدل
 * تكرار EMoney::create([...]) يدوياً.
 *
 * @extends Factory<EMoney>
 */
class EMoneyFactory extends Factory
{
    protected $model = EMoney::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'current_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ];
    }

    /** رصيد ابتدائي محدّد. */
    public function balance(string $amount): static
    {
        return $this->state(fn () => ['current_balance' => $amount]);
    }
}
