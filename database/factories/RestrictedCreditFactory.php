<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RestrictedCredit>
 */
final class RestrictedCreditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gift_card_type_id' => GiftCardType::factory(),
            'gift_card_id' => GiftCard::factory(),
            'balance' => fake()->randomElement([2500, 5000, 10000]),
        ];
    }

    /**
     * A specific balance in cents.
     */
    public function balance(int $cents): static
    {
        return $this->state(fn (array $attributes): array => [
            'balance' => $cents,
        ]);
    }
}
