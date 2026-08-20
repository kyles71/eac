<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons\RelationManagers;

use App\Actions\RecurringPrivateLessons\BillRecurringPrivateLessonBillingPeriod;
use App\Actions\RecurringPrivateLessons\RemoveRecurringPrivateLessonCharge;
use App\Actions\RecurringPrivateLessons\RescheduleRecurringPrivateLessonCharge;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonResolutionType;
use App\Enums\RecurringPrivateLessonStatus;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

final class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    protected static ?string $title = 'Lessons & Billing';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lesson Billing')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('event.start_time')
                        ->label('Lesson')
                        ->dateTime('M j, Y g:i A', timezone: $this->displayTimezone()),
                    TextEntry::make('amount')->money('USD', divideBy: 100),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('billed_at')
                        ->dateTime('M j, Y g:i A', timezone: $this->displayTimezone())
                        ->placeholder('Not billed'),
                    TextEntry::make('resolution_note')
                        ->label('Paid Removal Reason')
                        ->placeholder('No paid removal resolution'),
                ]),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('event.start_time')
                    ->label('Lesson')
                    ->dateTime('M j, Y g:i A', timezone: $this->displayTimezone())
                    ->icon(fn (RecurringPrivateLessonCharge $record): ?Icon => $this->notesIndicator(
                        $this->rescheduleNotesTooltip($record),
                    ))
                    ->iconPosition(IconPosition::After)
                    ->sortable(),
                TextColumn::make('billingPeriod.period_start')
                    ->label('Billing Month')
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->icon(fn (RecurringPrivateLessonCharge $record): ?Icon => $this->notesIndicator(
                        $this->statusNotesTooltip($record),
                    ))
                    ->iconPosition(IconPosition::After)
                    ->sortable(),
                TextColumn::make('billed_at')
                    ->label('Billed')
                    ->dateTime('M j, Y', timezone: $this->displayTimezone())
                    ->placeholder('Not billed'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    Action::make('billMonth')
                        ->label('Bill Month')
                        ->icon('heroicon-o-paper-airplane')
                        ->requiresConfirmation()
                        ->visible(fn (RecurringPrivateLessonCharge $record): bool => $this->canManage()
                            && $record->recurringPrivateLesson->status === RecurringPrivateLessonStatus::Active
                            && $record->status === RecurringPrivateLessonChargeStatus::Scheduled)
                        ->action(function (RecurringPrivateLessonCharge $record): void {
                            $this->runAction(
                                fn (): int => app(BillRecurringPrivateLessonBillingPeriod::class)
                                    ->handle($record->billingPeriod, $this->actor()),
                                'Monthly lessons billed',
                            );
                        }),
                    Action::make('reschedule')
                        ->label('Reschedule')
                        ->icon('heroicon-o-calendar-days')
                        ->modalDescription(fn (RecurringPrivateLessonCharge $record): string => $record->status === RecurringPrivateLessonChargeStatus::Paid
                            ? 'The existing payment stays attached to the rescheduled lesson.'
                            : 'The lesson duration will stay the same.')
                        ->fillForm(fn (RecurringPrivateLessonCharge $record): array => [
                            'starts_at' => $record->event->start_time,
                        ])
                        ->schema([
                            DateTimePicker::make('starts_at')
                                ->label('New Start Time')
                                ->required()
                                ->seconds(false)
                                ->minDate(fn (RecurringPrivateLessonCharge $record) => $record->status === RecurringPrivateLessonChargeStatus::Paid
                                    ? now()->startOfMinute()->addMinute()
                                    : now()->addDay()->startOfMinute()->addMinute())
                                ->timezone((string) config('app.display_timezone', config('app.timezone'))),
                            Textarea::make('reason')->required(),
                        ])
                        ->visible(fn (RecurringPrivateLessonCharge $record): bool => $this->canManage()
                            && $record->recurringPrivateLesson->status === RecurringPrivateLessonStatus::Active
                            && in_array($record->status, [
                                RecurringPrivateLessonChargeStatus::Scheduled,
                                RecurringPrivateLessonChargeStatus::Billed,
                                RecurringPrivateLessonChargeStatus::Paid,
                            ], true))
                        ->action(function (RecurringPrivateLessonCharge $record, array $data): void {
                            $this->runAction(function () use ($record, $data): int {
                                app(RescheduleRecurringPrivateLessonCharge::class)->handle(
                                    $record,
                                    CarbonImmutable::parse((string) $data['starts_at']),
                                    $this->actor(),
                                    (string) $data['reason'],
                                );

                                return 1;
                            }, 'Lesson rescheduled');
                        }),
                    Action::make('removeLesson')
                        ->label('Remove')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription(fn (RecurringPrivateLessonCharge $record): string => match ($record->status) {
                            RecurringPrivateLessonChargeStatus::Scheduled => 'This permanently removes the scheduled lesson. The family has not been asked to pay for it.',
                            RecurringPrivateLessonChargeStatus::Billed => 'This cancels the unpaid lesson and removes it from the family\'s available payments. It will remain listed as Cancelled.',
                            RecurringPrivateLessonChargeStatus::Paid => 'Choose unrestricted store credit or a refund. To keep the lesson and move it to another time, use Reschedule instead.',
                            default => 'This lesson cannot be removed.',
                        })
                        ->schema([
                            Select::make('payment_resolution')
                                ->label('Paid Lesson Resolution')
                                ->options([
                                    RecurringPrivateLessonResolutionType::Credit->value => 'Issue Store Credit',
                                    RecurringPrivateLessonResolutionType::Refund->value => 'Issue Refund',
                                ])
                                ->searchable(false)
                                ->required(fn (RecurringPrivateLessonCharge $record): bool => $record->status === RecurringPrivateLessonChargeStatus::Paid)
                                ->visible(fn (RecurringPrivateLessonCharge $record): bool => $record->status === RecurringPrivateLessonChargeStatus::Paid),
                            Textarea::make('reason')
                                ->label('Removal Reason')
                                ->required(),
                        ])
                        ->visible(fn (RecurringPrivateLessonCharge $record): bool => $this->canManage()
                            && in_array($record->status, [
                                RecurringPrivateLessonChargeStatus::Scheduled,
                                RecurringPrivateLessonChargeStatus::Billed,
                                RecurringPrivateLessonChargeStatus::Paid,
                            ], true)
                            && ! $record->event->isCancelled())
                        ->action(function (RecurringPrivateLessonCharge $record, array $data): void {
                            $this->runAction(function () use ($record, $data): int {
                                app(RemoveRecurringPrivateLessonCharge::class)->handle(
                                    $record,
                                    $this->actor(),
                                    (string) $data['reason'],
                                    RecurringPrivateLessonResolutionType::tryFrom((string) ($data['payment_resolution'] ?? '')),
                                );

                                return 1;
                            }, 'Lesson removed');
                        }),
                ]),
            ])
            ->defaultSort('id');
    }

    private function rescheduleNotesTooltip(RecurringPrivateLessonCharge $charge): ?string
    {
        $notes = collect($charge->reschedule_history ?? [])
            ->map(fn (mixed $entry): ?string => is_array($entry) && filled($entry['reason'] ?? null)
                ? (string) $entry['reason']
                : null)
            ->filter()
            ->join("\n");

        return $notes === '' ? null : $notes;
    }

    private function statusNotesTooltip(RecurringPrivateLessonCharge $charge): ?string
    {
        $notes = collect([
            $charge->event->cancellation_reason,
            $charge->resolution_note,
        ])->filter()->unique()->join("\n");

        return $notes === '' ? null : $notes;
    }

    private function notesIndicator(?string $notes): ?Icon
    {
        if ($notes === null) {
            return null;
        }

        return Icon::make(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('warning')
            ->size(IconSize::Small)
            ->tooltip($notes);
    }

    private function canManage(): bool
    {
        return $this->actor()->hasAnyRole(['owner', 'super_admin']);
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }

    private function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function runAction(callable $action, string $successTitle): void
    {
        try {
            $action();

            Notification::make()->title($successTitle)->success()->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('The action could not be completed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
