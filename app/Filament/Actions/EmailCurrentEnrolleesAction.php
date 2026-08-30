<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Mail\QueueHandcraftedEmail;
use App\Enums\EnrollmentEmailAudience;
use App\Models\Role;
use App\Models\User;
use App\Services\CurrentEnrollmentEmailRecipientsService;
use App\Support\Filament\EmailComposer;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

final class EmailCurrentEnrolleesAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Email Current Enrollees')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize(fn (): bool => $this->isOwner())
            ->disabled(fn (): bool => ! $this->currentTermExists())
            ->tooltip(fn (): string => $this->currentTermExists()
                ? 'Email everyone enrolled in the current academic term.'
                : 'No academic term is configured for today.')
            ->modalHeading('Email Current Enrollees')
            ->modalDescription(fn (): string => $this->currentTermDescription())
            ->schema([
                Radio::make('audience')
                    ->label('Recipients')
                    ->options(EnrollmentEmailAudience::class)
                    ->enum(EnrollmentEmailAudience::class)
                    ->default(EnrollmentEmailAudience::UserAccounts->value)
                    ->inline()
                    ->live()
                    ->required(),
                Text::make(fn (Get $get): string => $this->recipientSummary(
                    $this->audience($get('audience')),
                )),
                EmailComposer::subject(),
                EmailComposer::body(),
            ])
            ->action(function (array $data): void {
                $recipients = $this->recipients()->forAudience(
                    $this->audience($data['audience'] ?? null),
                );

                if ($recipients === []) {
                    Notification::make()
                        ->title('No matching recipients')
                        ->body('No email was queued.')
                        ->warning()
                        ->send();

                    return;
                }

                $queued = app(QueueHandcraftedEmail::class)->handle(
                    recipients: $recipients,
                    subject: (string) ($data['subject'] ?? ''),
                    body: (string) ($data['body'] ?? ''),
                    deliveryMode: QueueHandcraftedEmail::DELIVERY_MODE_INDIVIDUAL,
                    archiveTo: config('mail.mailers.handcrafted.archive_to', []),
                );

                $notification = Notification::make()
                    ->title($queued ? 'Email queued' : 'Handcrafted email is disabled');

                ($queued ? $notification->success() : $notification->warning())->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'emailCurrentEnrollees';
    }

    private function recipients(): CurrentEnrollmentEmailRecipientsService
    {
        return app(CurrentEnrollmentEmailRecipientsService::class);
    }

    private function audience(mixed $audience): EnrollmentEmailAudience
    {
        if ($audience instanceof EnrollmentEmailAudience) {
            return $audience;
        }

        return EnrollmentEmailAudience::tryFrom((string) $audience)
            ?? EnrollmentEmailAudience::UserAccounts;
    }

    private function recipientSummary(EnrollmentEmailAudience $audience): string
    {
        $count = $this->recipients()->countForAudience($audience);

        return $count.' unique '.str('email address')->plural($count).' will receive this message.';
    }

    private function currentTermDescription(): string
    {
        $academicTerm = $this->recipients()->currentTerm();

        return $academicTerm === null
            ? 'No academic term is configured for today.'
            : 'Current academic term: '.$academicTerm->display_name;
    }

    private function currentTermExists(): bool
    {
        return $this->recipients()->currentTerm() !== null;
    }

    private function isOwner(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasAnyRole([Role::OWNER, Role::SUPER_ADMIN]);
    }
}
