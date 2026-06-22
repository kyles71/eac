<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Students\Pages\ListStudents;
use App\Models\Student;
use Filament\Actions\CreateAction;

use function Pest\Livewire\livewire;

it('stores general student tags', function (): void {
    livewire(ListStudents::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'birthdate' => '2015-04-12',
            'tags' => ['has sibling'],
        ])
        ->assertNotified();

    $student = Student::query()->where('first_name', 'Avery')->firstOrFail();

    expect($student->tagsWithType(Student::GENERAL_TAG_TYPE)->pluck('name')->all())->toBe(['has sibling']);
});
