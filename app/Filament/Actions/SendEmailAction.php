<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Mail\HandcraftedEmail;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;

final class SendEmailAction extends Action
{
    /**
     * @var array<int, string>|Closure(): array<int, string>
     */
    protected array|Closure $defaultTo = [];

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
                /** @var array<int, string> $recipients */
                $recipients = $data['to'];

                Mail::mailer('handcrafted')
                    ->to($recipients)
                    ->queue(new HandcraftedEmail(
                        emailSubject: $data['subject'],
                        emailBody: $data['body'],
                    ));

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
     * @param  array<int, string>|Closure(): array<int, string>  $to
     */
    public function to(array|Closure $to): static
    {
        $this->defaultTo = $to;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    protected function getDefaultTo(): array
    {
        return $this->evaluate($this->defaultTo);
    }
}
