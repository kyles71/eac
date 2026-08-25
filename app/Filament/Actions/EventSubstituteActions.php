<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Events\ManageEventSubstitution;
use App\Enums\EventSubstituteCoverageStatus;
use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class EventSubstituteActions
{
    public static function group(?Event $event = null): ActionGroup
    {
        return ActionGroup::make([
            self::markNeeded(),
            self::request(),
            self::resend(),
            self::withdraw(),
            self::dismissRelease(),
            self::remove(),
            self::closeCoverage(),
            self::correctHistorical(),
        ])
            ->label(fn (?Event $record): string => self::coverageLabel($event ?? $record))
            ->icon(fn (?Event $record): Heroicon => self::coverageIcon(self::coverageStatus($event ?? $record)))
            ->color(fn (?Event $record): string => self::coverageStatus($event ?? $record)->getColor())
            ->dropdownWidth(Width::ExtraSmall)
            ->button();
    }

    public static function manage(string|Closure $url): Action
    {
        return Action::make('manageEventSubstitute')
            ->label(fn (?Event $record): string => self::coverageLabel($record))
            ->icon(fn (?Event $record): Heroicon => self::coverageIcon(self::coverageStatus($record)))
            ->color(fn (?Event $record): string => self::coverageStatus($record)->getColor())
            ->authorize('update')
            ->url($url);
    }

    public static function markNeeded(): Action
    {
        return Action::make('markSubstituteNeeded')
            ->label('Mark as Needing Substitute')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->authorize('update')
            ->visible(fn (Event $record): bool => $record->substitute_needed_at === null
                && $record->substitute_teacher_id === null
                && ! $record->isCancelled()
                && ! $record->isCompletedAt())
            ->requiresConfirmation()
            ->action(fn (Event $record): mixed => self::run(
                fn (User $user): Event => app(ManageEventSubstitution::class)->markNeeded($record, $user),
                'Event marked as needing a substitute',
            ))
            ->after(self::refreshRecord(...));
    }

    public static function request(): Action
    {
        return Action::make('requestEventSubstitute')
            ->label(fn (Event $record): string => $record->substitute_teacher_id === null
                ? 'Request Substitute'
                : 'Request Replacement')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->authorize('update')
            ->visible(fn (Event $record): bool => ! $record->isCancelled()
                && ! $record->isCompletedAt()
                && ! $record->pendingSubstituteRequest() instanceof EventSubstituteRequest)
            ->schema([
                Select::make('teacher_id')
                    ->label('Teacher')
                    ->options(self::teacherOptions(...))
                    ->searchable()
                    ->preload()
                    ->required(),
                Textarea::make('reason')
                    ->label('Reason / Instructions')
                    ->required(fn (Event $record): bool => $record->substitute_teacher_id !== null)
                    ->maxLength(2000),
            ])
            ->modalDescription('The teacher will receive an email and a portal banner asking them to accept or decline.')
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->action(function (Event $record, array $data): mixed {
                $teacher = User::query()->find($data['teacher_id'] ?? null);

                if (! $teacher instanceof User) {
                    Notification::make()->title('Teacher not found')->danger()->send();

                    return null;
                }

                return self::run(
                    fn (User $user): EventSubstituteRequest => app(ManageEventSubstitution::class)->requestSubstitute(
                        $record,
                        $teacher,
                        $user,
                        is_string($data['reason'] ?? null) ? $data['reason'] : null,
                    ),
                    'Substitute request sent',
                );
            })
            ->after(self::refreshRecord(...));
    }

    public static function resend(): Action
    {
        return Action::make('resendEventSubstituteRequest')
            ->label('Resend Substitute Request')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->authorize('update')
            ->visible(fn (Event $record): bool => $record->pendingSubstituteRequest() instanceof EventSubstituteRequest
                && ! $record->isCompletedAt())
            ->requiresConfirmation()
            ->action(function (Event $record): mixed {
                $request = $record->pendingSubstituteRequest();

                return $request instanceof EventSubstituteRequest
                    ? self::run(
                        fn (User $user): bool => app(ManageEventSubstitution::class)->resend($request, $user),
                        'Substitute request resent',
                    )
                    : null;
            })
            ->after(self::refreshRecord(...));
    }

    public static function withdraw(): Action
    {
        return Action::make('withdrawEventSubstituteRequest')
            ->label('Withdraw Pending Request')
            ->icon(Heroicon::OutlinedXMark)
            ->color('warning')
            ->authorize('update')
            ->visible(fn (Event $record): bool => $record->pendingSubstituteRequest() instanceof EventSubstituteRequest)
            ->schema([self::reasonField()])
            ->requiresConfirmation()
            ->action(fn (Event $record, array $data): mixed => self::run(
                fn (User $user): EventSubstituteRequest => app(ManageEventSubstitution::class)->withdrawPending(
                    $record,
                    $user,
                    (string) ($data['reason'] ?? ''),
                ),
                'Pending substitute request withdrawn',
            ))
            ->after(self::refreshRecord(...));
    }

    public static function dismissRelease(): Action
    {
        return Action::make('dismissSubstituteReleaseRequest')
            ->label('Dismiss Release Request')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->authorize('update')
            ->visible(fn (Event $record): bool => $record->currentSubstituteRequest()?->hasReleaseRequest() === true)
            ->requiresConfirmation()
            ->action(fn (Event $record): mixed => self::run(
                fn (User $user): EventSubstituteRequest => app(ManageEventSubstitution::class)->dismissReleaseRequest($record, $user),
                'Substitute release request dismissed',
            ))
            ->after(self::refreshRecord(...));
    }

    public static function remove(): Action
    {
        return Action::make('removeEventSubstitute')
            ->label('Remove Substitute Now')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->authorize('update')
            ->visible(fn (Event $record): bool => $record->substitute_teacher_id !== null && ! $record->isCompletedAt())
            ->schema([self::reasonField()])
            ->modalDescription('The teacher will lose access immediately and the event will remain marked as needing a substitute.')
            ->requiresConfirmation()
            ->action(fn (Event $record, array $data): mixed => self::run(
                fn (User $user): Event => app(ManageEventSubstitution::class)->removeCurrent(
                    $record,
                    $user,
                    (string) ($data['reason'] ?? ''),
                ),
                'Substitute removed',
            ))
            ->after(self::refreshRecord(...));
    }

    public static function closeCoverage(): Action
    {
        return Action::make('closeEventSubstituteCoverage')
            ->label('No Longer Needs Substitute')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->authorize('update')
            ->visible(fn (Event $record): bool => $record->substitute_needed_at !== null && ! $record->isCompletedAt())
            ->schema([self::reasonField()])
            ->requiresConfirmation()
            ->action(fn (Event $record, array $data): mixed => self::run(
                fn (User $user): Event => app(ManageEventSubstitution::class)->removeCurrent(
                    $record,
                    $user,
                    (string) ($data['reason'] ?? ''),
                    false,
                ),
                'Substitute coverage closed',
            ))
            ->after(self::refreshRecord(...));
    }

    public static function correctHistorical(): Action
    {
        return Action::make('correctHistoricalSubstitute')
            ->label('Correct Substitute Record')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->authorize('update')
            ->visible(fn (Event $record): bool => $record->isCompletedAt()
                && auth()->user() instanceof User
                && auth()->user()->hasAnyRole(['owner', 'super_admin']))
            ->schema([
                Select::make('teacher_id')
                    ->label('Teacher Who Substituted')
                    ->placeholder('No substitute')
                    ->options(self::teacherOptions(...))
                    ->searchable()
                    ->preload(),
                self::reasonField('Audit Reason'),
            ])
            ->fillForm(fn (Event $record): array => ['teacher_id' => $record->substitute_teacher_id])
            ->action(function (Event $record, array $data): mixed {
                $teacher = filled($data['teacher_id'] ?? null)
                    ? User::query()->find($data['teacher_id'])
                    : null;

                return self::run(
                    fn (User $user): ?EventSubstituteRequest => app(ManageEventSubstitution::class)->recordHistoricalCorrection(
                        $record,
                        $teacher instanceof User ? $teacher : null,
                        $user,
                        (string) ($data['reason'] ?? ''),
                    ),
                    'Historical substitute record corrected',
                );
            })
            ->after(self::refreshRecord(...));
    }

    /** @return array<int, string> */
    private static function teacherOptions(): array
    {
        return User::query()
            ->role('teacher')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName])
            ->all();
    }

    private static function reasonField(string $label = 'Reason'): Textarea
    {
        return Textarea::make('reason')
            ->label($label)
            ->required()
            ->maxLength(2000);
    }

    private static function coverageIcon(EventSubstituteCoverageStatus $status): Heroicon
    {
        return match ($status) {
            EventSubstituteCoverageStatus::NotNeeded => Heroicon::OutlinedAcademicCap,
            EventSubstituteCoverageStatus::NeedsSubstitute => Heroicon::OutlinedExclamationCircle,
            EventSubstituteCoverageStatus::AwaitingResponse => Heroicon::OutlinedClock,
            EventSubstituteCoverageStatus::Confirmed => Heroicon::OutlinedCheckCircle,
            EventSubstituteCoverageStatus::ReplacementPending => Heroicon::OutlinedArrowPath,
            EventSubstituteCoverageStatus::ReleaseRequested => Heroicon::OutlinedExclamationTriangle,
        };
    }

    private static function coverageLabel(?Event $event): string
    {
        return 'Substitute: '.self::coverageStatus($event)->getLabel();
    }

    private static function coverageStatus(?Event $event): EventSubstituteCoverageStatus
    {
        return $event?->substituteCoverageStatus() ?? EventSubstituteCoverageStatus::NotNeeded;
    }

    private static function refreshRecord(Event $record): void
    {
        $record->refresh();
    }

    private static function run(callable $callback, string $successTitle): mixed
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        try {
            $result = $callback($user);
            Notification::make()->title($successTitle)->success()->send();

            return $result;
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not update substitute coverage')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }
}
