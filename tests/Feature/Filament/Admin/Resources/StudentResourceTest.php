<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Students\Pages\ListStudents;
use App\Models\Calendar;
use App\Models\Student;
use Filament\Actions\CreateAction;
use Spatie\Tags\Tag;

use function Pest\Livewire\livewire;

it('stores general student tags separately from calendar audience tags', function (): void {
    $audienceTag = Tag::findOrCreate('Comp 25', Calendar::AUDIENCE_TAG_TYPE);

    livewire(ListStudents::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'tags' => ['has sibling'],
            'calendar_audience_tag_ids' => [$audienceTag->id],
        ])
        ->assertNotified();

    $student = Student::query()->where('first_name', 'Avery')->firstOrFail();

    expect($student->tagsWithType(Student::GENERAL_TAG_TYPE)->pluck('name')->all())->toBe(['has sibling'])
        ->and($student->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())->toBe(['Comp 25']);
});

it('does not create calendar audience tags from the student form', function (): void {
    $audienceTagCount = Tag::query()
        ->where('type', Calendar::AUDIENCE_TAG_TYPE)
        ->count();

    livewire(ListStudents::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Riley',
            'last_name' => 'North',
            'calendar_audience_tag_ids' => [999],
        ])
        ->assertHasActionErrors(['calendar_audience_tag_ids.0']);

    expect(Tag::query()->where('type', Calendar::AUDIENCE_TAG_TYPE)->count())->toBe($audienceTagCount);
});

it('does not allow internal audience tags to be assigned to students', function (string $tagName): void {
    $tag = Tag::findOrCreate($tagName, Calendar::AUDIENCE_TAG_TYPE);

    livewire(ListStudents::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Morgan',
            'last_name' => 'Vale',
            'calendar_audience_tag_ids' => [$tag->id],
        ])
        ->assertHasActionErrors(['calendar_audience_tag_ids.0']);
})->with([
    Calendar::AUDIENCE_TAG_OWNERS,
    Calendar::AUDIENCE_TAG_STAFF,
]);
