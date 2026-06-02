<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocumentVersion>
 */
final class LegalDocumentVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_document_id' => LegalDocument::factory(),
            'version' => 1,
            'title' => fake()->words(4, true),
            'content' => '<p>'.fake()->paragraph().'</p>',
            'published_at' => now(),
        ];
    }
}
