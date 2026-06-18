<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use FinityLabs\FinMail\Resources\EmailThemeResource\EmailThemeResource;
use Kyle\FilamentMailManager\Enums\LayoutMode;
use Kyle\FilamentMailManager\Filament\Pages\CompareEmailTemplateVersions;
use Kyle\FilamentMailManager\Filament\Pages\ManageEmailTypes;
use Kyle\FilamentMailManager\Filament\Pages\ManageLayoutSettings;
use Kyle\FilamentMailManager\Filament\Resources\MailLayouts\Pages\ListMailLayouts;
use Kyle\FilamentMailManager\Models\ManagedEmailTemplate;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    auth()->user()->givePermissionTo(Permission::findOrCreate('Manage:MailManager'));
});

it('registers the mail manager administration surfaces', function (): void {
    livewire(ManageEmailTypes::class)
        ->loadTable()
        ->assertSee('Password Reset')
        ->assertSee('Verify Email Address')
        ->assertSee('New User Welcome')
        ->assertSee('Handcrafted Email');

    $this->get(ListMailLayouts::getUrl())
        ->assertOk()
        ->assertSee('Layouts');

    $this->get(ManageLayoutSettings::getUrl())
        ->assertOk()
        ->assertSee('Default Layout');

    expect(Filament::getCurrentPanel()->getResources())
        ->not->toContain(EmailThemeResource::class);
});

it('renders two immutable email versions side by side', function (): void {
    $repository = app(ManagedTemplateRepository::class);
    $repository->saveOverride('user-password-reset', [
        'subject' => 'First subject',
        'body' => '<p>First body</p>{{ slot.action }}',
        'layout_mode' => LayoutMode::None,
    ]);
    $repository->saveOverride('user-password-reset', [
        'subject' => 'Second subject',
        'body' => '<p>Second body</p>{{ slot.action }}',
        'layout_mode' => LayoutMode::Inherited,
    ]);

    livewire(CompareEmailTemplateVersions::class, [
        'emailTypeKey' => 'user-password-reset',
        'leftVersion' => 1,
        'rightVersion' => 2,
    ])
        ->assertSee('Version 1')
        ->assertSee('Version 2')
        ->assertSee('Subject changed')
        ->assertSee('Layout changed');
});

it('only allows layout and enabled status to be configured for handcrafted email', function (): void {
    livewire(ManageEmailTypes::class)
        ->loadTable()
        ->mountAction(TestAction::make('edit')->table('handcrafted'))
        ->assertSchemaComponentExists('is_active', 'mountedActionSchema0')
        ->assertSchemaComponentExists('layout_mode', 'mountedActionSchema0')
        ->assertSchemaComponentDoesNotExist('subject', 'mountedActionSchema0')
        ->assertSchemaComponentDoesNotExist('body', 'mountedActionSchema0')
        ->assertSchemaComponentDoesNotExist('email_theme_id', 'mountedActionSchema0');
});

it('saves email type edits from array backed table records', function (): void {
    livewire(ManageEmailTypes::class)
        ->loadTable()
        ->callAction(TestAction::make('edit')->table('user-password-reset'), [
            'subject' => 'Reset your portal password',
            'body' => '<p>Use the secure reset link.</p>{{ slot.action }}',
            'is_active' => true,
            'layout_mode' => LayoutMode::None->value,
            'mail_layout_id' => null,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $template = ManagedEmailTemplate::query()
        ->where('key', 'user-password-reset')
        ->firstOrFail();

    expect($template)
        ->subject->toBe('Reset your portal password')
        ->layout_mode->toBe(LayoutMode::None);
});

it('denies a super admin mail manager access without the explicit mail manager permission', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    expect($user->hasPermissionTo('Manage:MailManager'))->toBeFalse();

    $this->get(ManageEmailTypes::getUrl())->assertForbidden();
    $this->get(ManageLayoutSettings::getUrl())->assertForbidden();
    $this->get(ListMailLayouts::getUrl())->assertForbidden();
});
