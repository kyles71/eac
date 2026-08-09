<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\SentEmails\Pages\ListSentEmails;
use App\Filament\Admin\Resources\SentEmails\SentEmailResource;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use FinityLabs\FinMail\Enums\EmailStatus;
use FinityLabs\FinMail\Resources\EmailThemeResource\EmailThemeResource;
use Kyle\FilamentMailManager\Enums\LayoutMode;
use Kyle\FilamentMailManager\Filament\Pages\CompareEmailTemplateVersions;
use Kyle\FilamentMailManager\Filament\Pages\ManageEmailTypes;
use Kyle\FilamentMailManager\Filament\Pages\ManageLayoutSettings;
use Kyle\FilamentMailManager\Filament\Resources\MailLayouts\Pages\ListMailLayouts;
use Kyle\FilamentMailManager\Filament\Resources\SentEmails\SentEmailResource as PackageSentEmailResource;
use Kyle\FilamentMailManager\Models\ManagedEmailTemplate;
use Kyle\FilamentMailManager\Models\ManagedSentEmail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    auth()->user()->givePermissionTo(Permission::findOrCreate('Manage:MailManager'));
});

it('registers the mail manager administration surfaces', function (): void {
    $emailTypesPage = livewire(ManageEmailTypes::class)
        ->loadTable()
        ->assertSee('Password Reset')
        ->assertSee('Verify Email Address')
        ->assertSee('New User Welcome')
        ->assertSee('Handcrafted Email');

    expect($emailTypesPage->instance()->getTable()->getRecordActions())
        ->toHaveCount(1)
        ->and($emailTypesPage->instance()->getTable()->getRecordActions()[0])
        ->toBeInstanceOf(ActionGroup::class);

    $this->get(ListMailLayouts::getUrl())
        ->assertOk()
        ->assertSee('Layouts');

    $this->get(ManageLayoutSettings::getUrl())
        ->assertOk()
        ->assertSee('Default Layout');

    expect(Filament::getCurrentPanel()->getResources())
        ->toContain(SentEmailResource::class)
        ->not->toContain(EmailThemeResource::class)
        ->not->toContain(PackageSentEmailResource::class)
        ->and(config('filament-shield.resources.exclude'))
        ->toContain(SentEmailResource::class);
});

it('omits unreliable sender information from sent email administration', function (): void {
    $email = ManagedSentEmail::create([
        'sender' => 'unreliable@example.com',
        'to' => ['recipient@example.com'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Administrative email preview',
        'rendered_body' => '<p>Message body</p>',
        'attachments' => [],
        'status' => EmailStatus::Sent,
        'sent_at' => now(),
        'metadata' => [],
        'email_type_key' => 'handcrafted',
    ]);

    livewire(ListSentEmails::class)
        ->loadTable()
        ->assertTableColumnExists('subject')
        ->assertTableColumnDoesNotExist('sender.name')
        ->assertCanSeeTableRecords([$email])
        ->mountAction(TestAction::make('view')->table($email))
        ->assertActionMounted(TestAction::make('view')->table($email))
        ->assertSchemaComponentDoesNotExist('sender', 'mountedActionSchema0')
        ->assertSchemaComponentExists('subject', 'mountedActionSchema0')
        ->assertSee('Administrative email preview')
        ->assertDontSee('unreliable@example.com');
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

it('grants a super admin mail manager access through the synchronized catalog', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    expect($user->hasPermissionTo('Manage:MailManager'))->toBeTrue();

    $this->get(ManageEmailTypes::getUrl())->assertOk();
    $this->get(ManageLayoutSettings::getUrl())->assertOk();
    $this->get(ListMailLayouts::getUrl())->assertOk();
});
