<?php

declare(strict_types=1);

use App\Filament\User\Pages\Messages;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use FinityLabs\FinMail\Enums\EmailStatus;
use Kyle\FilamentMailManager\Models\ManagedSentEmail;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('renders the email history page from the user panel route', function (): void {
    get(Messages::getUrl(panel: 'user'))
        ->assertOk()
        ->assertSee('Email History');
});

it('lists only sent emails addressed to the authenticated account by to or cc', function (): void {
    $user = User::factory()->create(['email' => 'primary@example.com']);
    actingAs($user);

    $toEmail = createManagedSentEmail([
        'to' => [$user->email],
        'subject' => 'Class reminder',
    ]);
    $ccEmail = createManagedSentEmail([
        'to' => ['office@example.com'],
        'cc' => [$user->email],
        'subject' => 'Copied announcement',
    ]);
    $otherEmail = createManagedSentEmail([
        'to' => ['other@example.com'],
        'subject' => 'Other family',
    ]);
    $bccOnlyEmail = createManagedSentEmail([
        'to' => ['archive@example.com'],
        'bcc' => [$user->email],
        'subject' => 'Archived copy',
    ]);
    $queuedEmail = createManagedSentEmail([
        'to' => [$user->email],
        'subject' => 'Queued message',
        'status' => EmailStatus::Queued,
    ]);
    $failedEmail = createManagedSentEmail([
        'to' => [$user->email],
        'subject' => 'Failed message',
        'status' => EmailStatus::Failed,
    ]);

    livewire(Messages::class)
        ->loadTable()
        ->assertTableColumnExists('sent_at')
        ->assertTableColumnExists('subject')
        ->assertTableColumnDoesNotExist('message_type')
        ->assertTableColumnDoesNotExist('sender')
        ->assertCanSeeTableRecords([$toEmail, $ccEmail])
        ->assertCanNotSeeTableRecords([$otherEmail, $bccOnlyEmail, $queuedEmail, $failedEmail])
        ->assertSee('Class reminder')
        ->assertSee('Copied announcement')
        ->assertDontSee('Other family')
        ->assertDontSee('Archived copy')
        ->assertDontSee('Queued message')
        ->assertDontSee('Failed message');
});

it('orders sent emails newest first and searches by subject', function (): void {
    $user = User::factory()->create(['email' => 'parent@example.com']);
    actingAs($user);

    $olderEmail = createManagedSentEmail([
        'to' => [$user->email],
        'subject' => 'Older receipt',
        'sent_at' => now()->subDays(2),
        'created_at' => now()->subDays(2),
    ]);
    $newerEmail = createManagedSentEmail([
        'to' => [$user->email],
        'subject' => 'Studio update',
        'sent_at' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);

    livewire(Messages::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$newerEmail, $olderEmail], true)
        ->searchTable('Studio')
        ->assertCanSeeTableRecords([$newerEmail])
        ->assertCanNotSeeTableRecords([$olderEmail]);
});

it('opens a read only preview for a visible sent email', function (): void {
    $user = User::factory()->create(['email' => 'viewer@example.com']);
    actingAs($user);
    $body = '<p>Hello from EAC</p>';
    $email = createManagedSentEmail([
        'to' => [$user->email],
        'subject' => 'Preview this email',
        'rendered_body' => $body,
        'email_type_key' => 'order-receipt',
    ]);

    livewire(Messages::class)
        ->loadTable()
        ->mountAction(TestAction::make('view')->table($email))
        ->assertActionMounted(TestAction::make('view')->table($email))
        ->assertSchemaComponentExists('subject', 'mountedActionSchema0')
        ->assertSchemaComponentDoesNotExist('sender', 'mountedActionSchema0')
        ->assertSchemaComponentExists('rendered_body', 'mountedActionSchema0')
        ->assertSchemaComponentVisible('rendered_body', 'mountedActionSchema0')
        ->assertSee('Preview this email');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createManagedSentEmail(array $attributes = []): ManagedSentEmail
{
    return ManagedSentEmail::create([
        'sender' => 'studio@example.com',
        'to' => ['recipient@example.com'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Message from EAC',
        'rendered_body' => '<p>Message body</p>',
        'attachments' => [],
        'status' => EmailStatus::Sent,
        'sent_at' => now(),
        'metadata' => [],
        'email_type_key' => 'handcrafted',
        ...$attributes,
    ]);
}
