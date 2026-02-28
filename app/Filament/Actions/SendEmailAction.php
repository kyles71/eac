<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Mail\GenericMail;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;

class SendEmailAction extends Action
{
    /**
     * @var array<int, string>|Closure(): array<int, string>
     */
    protected array|Closure $defaultTo = [];

    public static function getDefaultName(): ?string
    {
        return 'sendEmail';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Send Email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->form(fn (): array => [
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

                Mail::to($recipients)
                    ->send(new GenericMail(
                        emailSubject: $data['subject'],
                        emailBody: $data['body'],
                    ));

                Notification::make()
                    ->title('Email sent')
                    ->success()
                    ->send();
            });
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
        return $this->defaultTo instanceof Closure
            ? ($this->defaultTo)()
            : $this->defaultTo;
    }
}
