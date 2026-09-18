<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Forms\DefaultFormDefinitions;
use App\Models\Form;
use Illuminate\Database\Seeder;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;

final class ShowcaseParticipationFormSeeder extends Seeder
{
    public function run(
        DefaultFormDefinitions $definitions,
    ): void {
        $form = Form::query()->firstOrCreate(
            ['key' => 'showcase-participation'],
            [
                'name' => 'Showcase Participation',
                'updates_allowed' => false,
                'update_strategy' => null,
            ],
        );
        $form->versions()->where('status', FormVersionStatus::Draft)->first()
            ?? $form->createDraftVersion(
                schema: $definitions->showcaseParticipation(),
                requiresSignature: true,
                label: 'Showcase template — copy and label for an actual event',
            );
    }
}
