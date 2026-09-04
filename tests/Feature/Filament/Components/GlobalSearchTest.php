<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\BoardItems\BoardItemResource;
use App\Filament\Admin\Resources\Boards\BoardResource;
use App\Filament\Admin\Resources\Calendars\CalendarResource;
use App\Filament\Admin\Resources\DashboardMessages\DashboardMessageResource;
use App\Filament\Admin\Resources\DashboardQuickLinks\DashboardQuickLinkResource;
use App\Filament\Admin\Resources\DiscountCodes\DiscountCodeResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Gear\GearResource;
use App\Filament\Admin\Resources\GiftCards\GiftCardResource;
use App\Filament\Admin\Resources\GiftCardTypes\GiftCardTypeResource;
use App\Filament\Admin\Resources\LegalDocuments\LegalDocumentResource;
use App\Filament\Admin\Resources\ManagedBanners\ManagedBannerResource;
use App\Filament\Admin\Resources\PaymentPlanTemplates\PaymentPlanTemplateResource;
use App\Filament\Admin\Resources\RecurringPrivateLessons\RecurringPrivateLessonResource;
use App\Filament\Admin\Resources\StudentCommunications\StudentCommunicationResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Clusters\Settings\Resources\Holidays\HolidayResource;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\LegalDocument;
use App\Models\PaymentPlanTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Livewire\GlobalSearch;

use function Pest\Livewire\livewire;

it('can global search', function (): void {
    Calendar::factory()->create(['name' => 'Test Calendar']);
    LegalDocument::factory()->create(['name' => 'Test Legal Document']);
    PaymentPlanTemplate::factory()->create(['name' => 'Test Payment Plan Template']);

    livewire(GlobalSearch::class)
        ->set('search', 'test')
        ->assertOk();
});

it('keeps resources out of global search when they have no record-level view ability', function (): void {
    expect(CalendarResource::canGloballySearch())->toBeFalse()
        ->and(BoardResource::canGloballySearch())->toBeFalse()
        ->and(BoardItemResource::canGloballySearch())->toBeFalse()
        ->and(GearResource::canGloballySearch())->toBeFalse()
        ->and(DashboardMessageResource::canGloballySearch())->toBeFalse()
        ->and(DashboardQuickLinkResource::canGloballySearch())->toBeFalse()
        ->and(DiscountCodeResource::canGloballySearch())->toBeFalse()
        ->and(GiftCardResource::canGloballySearch())->toBeFalse()
        ->and(GiftCardTypeResource::canGloballySearch())->toBeFalse()
        ->and(HolidayResource::canGloballySearch())->toBeFalse()
        ->and(LegalDocumentResource::canGloballySearch())->toBeFalse()
        ->and(ManagedBannerResource::canGloballySearch())->toBeFalse()
        ->and(PaymentPlanTemplateResource::canGloballySearch())->toBeFalse()
        ->and(RecurringPrivateLessonResource::canGloballySearch())->toBeFalse()
        ->and(StudentCommunicationResource::canGloballySearch())->toBeFalse();
});

it('can global search for users', function (string $attribute): void {
    $record = User::factory()->create();

    UserResource::getGlobalSearchResults($record->{$attribute})
        ->each(function (GlobalSearchResult $result) use ($record): void {
            expect($result->title)->toBe($record->fullName);
        });
})->with(UserResource::getGloballySearchableAttributes());

it('can global search for an event assigned to a confirmed substitute', function (): void {
    Filament::setCurrentPanel('admin');
    $teacher = User::factory()->isTeacher()->create();
    $event = Event::factory()->create([
        'course_id' => Course::factory(),
        'substitute_teacher_id' => $teacher->id,
    ]);
    $event->update(['name' => 'Kinderballet Substitute Assignment']);

    $this->actingAs($teacher);

    expect(EventResource::getGlobalSearchResults('Kinderballet'))->toHaveCount(1);
});
