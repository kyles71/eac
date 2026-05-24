<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Mail\QueueHandcraftedEmail;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Filament action for composing and queueing handcrafted emails.
 *
 * Usage options:
 * - to(array|Closure $to): Sets the default recipient addresses shown in the modal.
 * - deliveryMode(string|Closure $mode): Overrides the configured delivery mode for this action instance.
 *       Use SendEmailAction::DELIVERY_MODE_INDIVIDUAL to queue one message per recipient, or
 *       SendEmailAction::DELIVERY_MODE_GROUPED to queue one message to all recipients.
 * - archiveTo(array|Closure|null $recipients): Overrides the configured archive/self-copy recipients.
 *       Textmagic sends archive recipients as a separate message; other mailers receive archive copies by BCC.
 * - withoutArchiveCopy(): Disables archive/self-copy recipients for this action instance.
 *
 * Defaults come from mail.mailers.handcrafted.delivery_mode and mail.mailers.handcrafted.archive_to.
 */
final class SendEmailAction extends Action
{
    public const string DELIVERY_MODE_GROUPED = QueueHandcraftedEmail::DELIVERY_MODE_GROUPED;

    public const string DELIVERY_MODE_INDIVIDUAL = QueueHandcraftedEmail::DELIVERY_MODE_INDIVIDUAL;

    /**
     * @var array<int, string>|Closure(): array<int, string>
     */
    protected array|Closure $defaultTo = [];

    /**
     * @var string|Closure(): string|null
     */
    protected string|Closure|null $deliveryMode = null;

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
                TagsInput::make('to')
                    ->label('To')
                    ->default($this->getDefaultTo())
                    ->nestedRecursiveRules(['email'])
                    ->placeholder('Add email address')
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

    public static function getDefaultName(): ?string
    {
        return 'sendEmail';
    }

    /**
     * Set the default recipient addresses shown in the action form.
     *
     * @param  array<int, string>|Closure(): array<int, string>  $to
     */
    public function to(array|Closure $to): static
    {
        $this->defaultTo = $to;

        return $this;
    }

    /**
     * Override the configured delivery mode for this action instance.
     */
    public function deliveryMode(string|Closure $mode): static
    {
        $this->deliveryMode = $mode;

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
     * @return array<int, string>
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
            recipients: $data['to'] ?? [],
            subject: (string) ($data['subject'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            deliveryMode: $this->getDeliveryMode(),
            archiveTo: $this->getArchiveTo(),
        );
    }

    private function getDeliveryMode(): mixed
    {
        return $this->deliveryMode === null
            ? config('mail.mailers.handcrafted.delivery_mode', self::DELIVERY_MODE_INDIVIDUAL)
            : $this->evaluate($this->deliveryMode);
    }

    private function getArchiveTo(): mixed
    {
        if (! $this->archiveCopyEnabled) {
            return [];
        }

        if ($this->hasArchiveToOverride) {
            return $this->evaluate($this->archiveTo) ?? [];
        }

        return config('mail.mailers.handcrafted.archive_to', []);
    }
}
