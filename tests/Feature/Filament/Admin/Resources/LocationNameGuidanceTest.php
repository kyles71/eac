<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Support\LocationNameGuidance;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('shows location naming guidance when creating courses and events', function (string $page): void {
    livewire($page)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentExists(
            'name',
            checkComponentUsing: fn (TextInput $input): bool => str_contains(
                (string) $input->getChildSchema(TextInput::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString(),
                LocationNameGuidance::HELP_TEXT,
            ),
        );
})->with([
    'course' => ListCourses::class,
    'event' => ListEvents::class,
]);
