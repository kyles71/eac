<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TextMessageBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TextMessageBatch> */
final class TextMessageBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'submission_token' => (string) Str::uuid(),
            'author_id' => User::factory(),
            'author_name' => fake()->name(),
            'body' => 'EAC: Classes are cancelled today.',
            'provider' => 'textmagic',
            'sender' => '+13135550100',
            'events' => [],
            'warnings' => [],
            'review_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
