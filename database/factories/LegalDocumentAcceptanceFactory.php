<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LegalDocumentAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocumentAcceptance>
 */
final class LegalDocumentAcceptanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_document_version_id' => LegalDocumentVersion::factory(),
            'user_id' => User::factory(),
            'acceptable_type' => (new Order)->getMorphClass(),
            'acceptable_id' => Order::factory(),
            'accepted_at' => now(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
