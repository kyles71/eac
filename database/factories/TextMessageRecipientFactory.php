<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TextMessageStatus;
use App\Models\TextMessageBatch;
use App\Models\TextMessageRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TextMessageRecipient> */
final class TextMessageRecipientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_id' => TextMessageBatch::factory(),
            'phone' => '+13135550123',
            'sources' => [],
            'status' => TextMessageStatus::Pending,
            'available_at' => now(),
        ];
    }
}
