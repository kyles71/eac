<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Events\CancelEvent;
use App\Models\Event;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class CancelEventAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Cancel Event')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->visible(fn (?Event $record): bool => $record instanceof Event && $record->canBeCancelledAt())
            ->modalHeading(fn (Event $record): string => "Cancel {$record->name}")
            ->modalDescription('The event will remain visible as cancelled. The cancellation reason is required.')
            ->schema([
                Textarea::make('reason')
                    ->label('Cancellation Reason')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000),
            ])
            ->modalSubmitActionLabel('Cancel and Send Email')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction(
                    'cancelWithoutEmail',
                    arguments: ['send_email' => false],
                )
                    ->label('Cancel Without Sending Email')
                    ->color('warning'),
            ])
            ->modalCancelActionLabel('Cancel / Close')
            ->action(function (Event $record, array $data, array $arguments): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $sendEmail = (bool) ($arguments['send_email'] ?? true);

                try {
                    $queued = app(CancelEvent::class)->handle(
                        event: $record,
                        cancelledBy: $user,
                        reason: (string) ($data['reason'] ?? ''),
                        sendEmail: $sendEmail,
                    );

                    $notification = Notification::make()->title(
                        $sendEmail ? 'Event cancelled' : 'Event cancelled without sending email'
                    );

                    if ($sendEmail && $queued === 0) {
                        $notification
                            ->body('No cancellation emails were queued.')
                            ->warning()
                            ->send();

                        return;
                    }

                    if ($sendEmail) {
                        $notification->body($queued === 1
                            ? '1 cancellation email was queued.'
                            : "{$queued} cancellation emails were queued.");
                    }

                    $notification->success()->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not cancel event')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getDefaultName(): string
    {
        return 'cancelEvent';
    }
}
