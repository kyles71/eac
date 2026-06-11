<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Mail\QueueHandcraftedEmail;
use App\Models\Student;
use App\Models\User;
use App\Support\HandcraftedEmailRecipients;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Filament action for composing and queueing handcrafted emails.
 *
 * Usage options:
 * - to(array|Closure $to): Sets the default recipients shown in the modal.
 * - archiveTo(array|Closure|null $recipients): Overrides the configured archive/self-copy recipients.
 *       Textmagic sends archive recipients as a separate message; other mailers receive archive copies by BCC.
 * - withoutArchiveCopy(): Disables archive/self-copy recipients for this action instance.
 */
final class SendEmailAction extends Action
{
    /**
     * @var array<int, Student|User|string>|Closure
     */
    protected array|Closure $defaultTo = [];

    /**
     * @var array<int, string>|Closure(): array<int, string>|null
     */
    protected array|Closure|null $archiveTo = null;

    protected bool $hasArchiveToOverride = false;

    protected bool $archiveCopyEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Send Email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->slideOver(false)
            ->schema(fn (): array => [
                Select::make('to')
                    ->label('To')
                    ->multiple()
                    ->searchable()
                    ->searchDebounce(500)
                    ->searchPrompt('Type at least 3 characters to search students or teachers, or enter a complete email address.')
                    ->searchingMessage('Searching recipients...')
                    ->noSearchResultsMessage('No matching students, teachers, or email address.')
                    ->getSearchResultsUsing(
                        fn (string $search): array => app(HandcraftedEmailRecipients::class)->search($search)
                    )
                    ->getOptionLabelsUsing(
                        fn (array $values): array => app(HandcraftedEmailRecipients::class)->labels($values)
                    )
                    ->default(app(HandcraftedEmailRecipients::class)->defaultValues($this->getDefaultTo()))
                    ->placeholder('Add recipients')
                    ->required(),
                TextInput::make('subject')
                    ->label('Subject')
                    ->required(),
                Textarea::make('body')
                    ->label('Body')
                    ->rows(5)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->queueEmails($data);

                Notification::make()
                    ->title('Email queued')
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'sendEmail';
    }

    /**
     * Set the default recipients shown in the action form.
     *
     * @param  array<int, Student|User|string>|Closure  $to
     */
    public function to(array|Closure $to): static
    {
        $this->defaultTo = $to;

        return $this;
    }

    /**
     * Override the configured archive/self-copy recipients for this action instance.
     *
     * @param  array<int, string>|Closure(): array<int, string>|null  $recipients
     */
    public function archiveTo(array|Closure|null $recipients): static
    {
        $this->archiveTo = $recipients;
        $this->hasArchiveToOverride = true;

        return $this;
    }

    /**
     * Disable archive/self-copy recipients for this action instance.
     */
    public function withoutArchiveCopy(): static
    {
        $this->archiveCopyEnabled = false;

        return $this;
    }

    /**
     * @return array<int, Student|User|string>
     */
    protected function getDefaultTo(): array
    {
        return $this->evaluate($this->defaultTo);
    }

    /**
     * @param  array{to?: mixed, subject?: mixed, body?: mixed}  $data
     */
    private function queueEmails(array $data): void
    {
        app(QueueHandcraftedEmail::class)->handle(
            recipients: app(HandcraftedEmailRecipients::class)->resolve($data['to'] ?? []),
            subject: (string) ($data['subject'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            deliveryMode: QueueHandcraftedEmail::DELIVERY_MODE_INDIVIDUAL,
            archiveTo: $this->getArchiveTo(),
        );
    }

    private function getArchiveTo(): mixed
    {
        if (! $this->archiveCopyEnabled) {
            return [];
        }

        if ($this->hasArchiveToOverride) {
            if ($this->archiveTo === null) {
                return [];
            }

            return $this->evaluate($this->archiveTo);
        }

        return config('mail.mailers.handcrafted.archive_to', []);
    }
}
