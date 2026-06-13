<?php

declare(strict_types=1);

use App\Enums\DashboardAudience;
use App\Filament\Admin\Pages\Dashboard as AdminDashboard;
use App\Filament\Admin\Resources\DashboardMessages\Pages\CreateDashboardMessage;
use App\Filament\Admin\Resources\DashboardMessages\Pages\ListDashboardMessages;
use App\Filament\Admin\Resources\DashboardQuickLinks\Pages\CreateDashboardQuickLink;
use App\Filament\Admin\Resources\DashboardQuickLinks\Pages\ListDashboardQuickLinks;
use App\Filament\Clusters\Settings\Pages\ManageDashboardAppearance;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Filament\Shared\Widgets\MessagesFromEac;
use App\Filament\Shared\Widgets\QuickLinks;
use App\Filament\User\Pages\Checkout;
use App\Filament\User\Pages\MyEnrollments;
use App\Filament\User\Resources\Students\StudentResource;
use App\Models\DashboardMessage;
use App\Models\DashboardQuickLink;
use App\Models\User;
use App\Services\DashboardQuickLinkDestinationService;
use App\Settings\DashboardAppearanceSettings;
use App\Support\MediaDisks;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('manages dashboard messages from settings', function (): void {
    livewire(CreateDashboardMessage::class)
        ->fillForm([
            'message' => 'Costume orders due Monday.',
            'audience' => DashboardAudience::Semester->value,
            'published_at' => now()->subMinute(),
            'expires_at' => now()->addWeek(),
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas(DashboardMessage::class, [
        'message' => 'Costume orders due Monday.',
        'audience' => DashboardAudience::Semester->value,
    ]);

    livewire(ListDashboardMessages::class)
        ->loadTable()
        ->assertCanSeeTableRecords(DashboardMessage::all())
        ->assertTableColumnExists('status')
        ->assertTableFilterExists('audience')
        ->assertTableFilterExists('status');
});

it('uses enum-backed dashboard audience presentation and base-10 priorities', function (): void {
    $expectedOptions = [
        DashboardAudience::Eac->value => 'EAC Audience',
        DashboardAudience::Semester->value => 'Semester Audience',
        DashboardAudience::CompTeam->value => 'Comp Team Audience',
        DashboardAudience::Teacher->value => 'Teacher Audience',
        DashboardAudience::Owner->value => 'Owner Audience',
    ];

    expect(DashboardAudience::cases())
        ->toBe([
            DashboardAudience::Eac,
            DashboardAudience::Semester,
            DashboardAudience::CompTeam,
            DashboardAudience::Teacher,
            DashboardAudience::Owner,
        ])
        ->and(DashboardAudience::CompTeam->getLabel())->toBe('Comp Team Audience')
        ->and(DashboardAudience::CompTeam->getColor())->toBe('primary')
        ->and(array_map(
            fn (DashboardAudience $audience): int => $audience->priority(),
            DashboardAudience::cases(),
        ))->toBe([50, 40, 30, 20, 10]);

    livewire(CreateDashboardMessage::class)
        ->assertSchemaComponentExists(
            'audience',
            checkComponentUsing: fn (Select $select): bool => $select->getOptions() === $expectedOptions,
        );

    livewire(CreateDashboardQuickLink::class)
        ->assertSchemaComponentExists(
            'audience',
            checkComponentUsing: fn (Select $select): bool => $select->getOptions() === $expectedOptions,
        );
});

it('manages internal and external dashboard quick links', function (): void {
    livewire(CreateDashboardQuickLink::class)
        ->fillForm([
            'label' => 'My Classes',
            'audience' => DashboardAudience::Eac->value,
            'destination' => MyEnrollments::class,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    livewire(CreateDashboardQuickLink::class)
        ->fillForm([
            'label' => 'Studio Website',
            'audience' => DashboardAudience::Owner->value,
            'destination' => DashboardQuickLinkDestinationService::EXTERNAL,
            'external_url' => 'https://example.com',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas(DashboardQuickLink::class, [
        'label' => 'My Classes',
        'external_url' => null,
    ]);
    assertDatabaseHas(DashboardQuickLink::class, [
        'label' => 'Studio Website',
        'external_url' => 'https://example.com',
    ]);

    livewire(ListDashboardQuickLinks::class)
        ->loadTable()
        ->assertCanSeeTableRecords(DashboardQuickLink::all())
        ->assertTableFilterExists('audience')
        ->assertTableFilterExists('is_active');
});

it('rejects non-http external quick link urls', function (): void {
    livewire(CreateDashboardQuickLink::class)
        ->fillForm([
            'label' => 'Unsafe',
            'audience' => DashboardAudience::Eac->value,
            'destination' => DashboardQuickLinkDestinationService::EXTERNAL,
            'external_url' => 'javascript:alert(1)',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['external_url']);
});

it('offers searchable navigable user panel destinations dynamically', function (): void {
    $destinations = app(DashboardQuickLinkDestinationService::class);

    livewire(CreateDashboardQuickLink::class)
        ->assertSchemaComponentExists(
            'destination',
            checkComponentUsing: fn (Select $select): bool => $select->isSearchable()
                && $select->getOptions() === $destinations->options(),
        );

    expect($destinations->options())
        ->toHaveKey(MyEnrollments::class, 'My Classes')
        ->toHaveKey(StudentResource::class, 'Students')
        ->not->toHaveKey(Checkout::class)
        ->and($destinations->urlFor(MyEnrollments::class))
        ->toContain('/dancefam/my-enrollments');
});

it('uses shared communication widgets and calendar on the admin dashboard', function (): void {
    expect((new AdminDashboard)->getWidgets())->toBe([
        MessagesFromEac::class,
        QuickLinks::class,
        CalendarWidget::class,
    ]);
});

it('configures dashboard bullet images from a permission-gated settings page', function (): void {
    $acceptedFileTypes = [
        'image/jpeg',
        'image/png',
        'image/svg+xml',
        'image/webp',
    ];

    livewire(ManageDashboardAppearance::class)
        ->assertSee('Cropping or editing an SVG converts it to PNG.')
        ->assertSchemaComponentExists(
            'messages_bullet_image',
            checkComponentUsing: fn (FileUpload $field): bool => $field->getAcceptedFileTypes() === $acceptedFileTypes
                && $field->getDiskName() === MediaDisks::public()
                && $field->getVisibility() === 'public'
                && $field->getDirectory() === 'dashboard/bullets'
                && $field->getImageAspectRatio() === '1:1'
                && $field->hasImageEditor()
                && $field->shouldAutomaticallyOpenImageEditorForAspectRatio()
                && $field->isSvgEditingConfirmed(),
        )
        ->assertSchemaComponentExists(
            'quick_links_bullet_image',
            checkComponentUsing: fn (FileUpload $field): bool => $field->getAcceptedFileTypes() === $acceptedFileTypes
                && $field->getImageAspectRatio() === '1:1',
        );

    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();

    expect(Role::findByName('super_admin')->hasPermissionTo('Manage:DashboardAppearance'))->toBeTrue()
        ->and($owner->can('Manage:DashboardAppearance'))->toBeTrue()
        ->and($teacher->can('Manage:DashboardAppearance'))->toBeFalse();

    $this->actingAs($owner);
    expect(ManageDashboardAppearance::canAccess())->toBeTrue();

    $this->actingAs($teacher);
    expect(ManageDashboardAppearance::canAccess())->toBeFalse();
});

it('deletes a removed dashboard bullet image after saving settings', function (): void {
    Storage::fake(MediaDisks::public());

    $oldPath = 'dashboard/bullets/old-message-bullet.png';
    Storage::disk(MediaDisks::public())->put($oldPath, 'old image');

    $settings = app(DashboardAppearanceSettings::class);
    $settings->messages_bullet_image = $oldPath;
    $settings->quick_links_bullet_image = null;
    $settings->save();

    livewire(ManageDashboardAppearance::class)
        ->fillForm([
            'messages_bullet_image' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $settings->refresh();

    expect(Storage::disk(MediaDisks::public())->missing($oldPath))->toBeTrue()
        ->and($settings->messages_bullet_image)->toBeNull();
});

it('accepts svg dashboard bullet images', function (): void {
    Storage::fake(MediaDisks::public());

    $svg = UploadedFile::fake()
        ->createWithContent('message-bullet.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="5"/></svg>')
        ->mimeType('image/svg+xml');

    livewire(ManageDashboardAppearance::class)
        ->fillForm([
            'messages_bullet_image' => $svg,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $settings = app(DashboardAppearanceSettings::class)->refresh();

    expect($settings->messages_bullet_image)->toEndWith('.svg')
        ->and(Storage::disk(MediaDisks::public())->exists($settings->messages_bullet_image))->toBeTrue();
});
