<?php

namespace Database\Factories;

use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => 'cash',
            'is_default' => false,
            'is_active' => true,
            'allow_negative' => false,
        ];
    }

    public function bank(): static
    {
        return $this->state(['type' => 'bank']);
    }

    public function asDefault(): static
    {
        return $this->state(['is_default' => true]);
    }
}
