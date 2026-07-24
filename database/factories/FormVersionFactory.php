<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;

/**
 * @extends Factory<FormVersion>
 */
final class FormVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'version' => 1,
            'status' => FormVersionStatus::Draft,
            'schema' => [],
            'requires_signature' => false,
            'label' => null,
            'activation_starts_at' => null,
            'activation_ends_at' => null,
            'activated_at' => null,
            'deactivated_at' => null,
            'published_by_type' => null,
            'published_by_id' => null,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this
            ->state(fn (): array => [
                'status' => FormVersionStatus::Draft,
                'activation_starts_at' => null,
                'activated_at' => null,
                'published_at' => null,
            ])
            ->afterCreating(function (FormVersion $version): void {
                DB::table($version->getTable())
                    ->where('id', $version->id)
                    ->update([
                        'status' => FormVersionStatus::Published->value,
                        'draft_marker' => null,
                        'activation_starts_at' => now(),
                        'activated_at' => now(),
                        'published_at' => now(),
                    ]);
                $version->form()->update(['active_version_id' => $version->id]);
                $version->refresh();
            });
    }
}
