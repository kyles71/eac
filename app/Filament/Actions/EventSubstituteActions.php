<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Events\ManageEventSubstitution;
use App\Enums\EventSubstituteCoverageStatus;
use App\Enums\EventSubstituteRequestReason;
use App\Models\Event;
use App\Models\EventSubstituteCoverage;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use App\Services\TeacherScheduleConflictService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
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
            ->visible(fn (Event $record): bool => $record->teachers()->exists()
                && ! $record->isCancelled()
                && ! $record->isCompletedAt())
            ->schema([
                Select::make('covered_teacher_id')
                    ->label('Teacher Being Covered')
                    ->options(fn (Event $record): array => self::regularTeacherOptions($record, excludeActive: true))
                    ->required(),
            ])
            ->action(function (Event $record, array $data): mixed {
                $coveredTeacher = User::query()->find($data['covered_teacher_id'] ?? null);

                if (! $coveredTeacher instanceof User) {
                    Notification::make()->title('Teacher not found')->danger()->send();

                    return null;
                }

                return self::run(
                    fn (User $user): EventSubstituteCoverage => app(ManageEventSubstitution::class)->markNeeded(
                        $record,
                        $coveredTeacher,
                        $user,
                    ),
                    'Event marked as needing a substitute',
                );
            })
            ->after(self::refreshRecord(...));
    }

    public static function request(): Action
    {
        return Action::make('requestEventSubstitute')
            ->label('Request Substitute')
            ->modalHeading('Request Substitute')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->authorize('update')
            ->visible(fn (Event $record): bool => ! $record->isCancelled()
                && ! $record->isCompletedAt()
                && $record->teachers()->exists())
            ->schema([
                Select::make('covered_teacher_id')
                    ->label('Teacher Being Covered')
                    ->options(fn (Event $record): array => self::regularTeacherOptions($record))
                    ->required(),
                Select::make('teacher_id')
                    ->label('Substitute Teacher')
                    ->options(fn (Event $record): array => self::substituteOptions($record))
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('reason_type')
                    ->label('Reason')
                    ->options(EventSubstituteRequestReason::class)
                    ->live()
                    ->selectablePlaceholder(false)
                    ->required(),
                Textarea::make('reason')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => self::substituteRequestReasonType($get('reason_type')) === EventSubstituteRequestReason::Other)
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
                        User::query()->findOrFail($data['covered_teacher_id']),
                        $teacher,
                        $user,
                        self::substituteRequestReason($data),
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
            ->schema([
                Select::make('request_id')
                    ->label('Pending Request')
                    ->options(fn (Event $record): array => self::pendingRequestOptions($record))
                    ->required(),
            ])
            ->action(function (Event $record, array $data): mixed {
                $request = $record->substituteRequests()->pending()->find($data['request_id'] ?? null);

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
            ->schema([
                Select::make('coverage_id')
                    ->label('Teacher Coverage')
                    ->options(fn (Event $record): array => self::coverageOptions($record, pendingOnly: true))
                    ->required(),
                self::reasonField(),
            ])
            ->requiresConfirmation()
            ->action(fn (array $data): mixed => self::run(
                fn (User $user): EventSubstituteRequest => app(ManageEventSubstitution::class)->withdrawPending(
                    EventSubstituteCoverage::query()->findOrFail($data['coverage_id']),
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
            ->visible(fn (Event $record): bool => $record->currentSubstituteCoverages()->contains(
                fn (EventSubstituteCoverage $coverage): bool => $coverage->currentSubstituteRequest()?->hasReleaseRequest() === true,
            ))
            ->schema([
                Select::make('coverage_id')
                    ->label('Teacher Coverage')
                    ->options(fn (Event $record): array => self::coverageOptions($record, releaseOnly: true))
                    ->required(),
            ])
            ->action(fn (array $data): mixed => self::run(
                fn (User $user): EventSubstituteRequest => app(ManageEventSubstitution::class)->dismissReleaseRequest(
                    EventSubstituteCoverage::query()->findOrFail($data['coverage_id']),
                    $user,
                ),
                'Substitute release request dismissed',
            ))
            ->after(self::refreshRecord(...));
    }

    public static function requestRelease(): Action
    {
        return Action::make('requestRelease')
            ->label('I Can No Longer Cover This Event')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->authorize('requestSubstituteRelease')
            ->visible(fn (Event $record): bool => auth()->user() instanceof User
                && $record->isConfirmedSubstitute(auth()->user())
                && ! $record->isCompletedAt())
            ->schema([
                Select::make('coverage_id')
                    ->label('Teacher Covered')
                    ->options(fn (Event $record): array => self::coverageOptionsForCurrentSubstitute($record))
                    ->required(),
                self::reasonField(),
            ])
            ->modalDescription('You remain assigned until staff removes or replaces you.')
            ->requiresConfirmation()
            ->action(fn (array $data): mixed => self::run(
                fn (User $user): EventSubstituteRequest => app(ManageEventSubstitution::class)->requestRelease(
                    EventSubstituteCoverage::query()->findOrFail($data['coverage_id']),
                    $user,
                    (string) ($data['reason'] ?? ''),
                ),
                'Release requested',
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
            ->visible(fn (Event $record): bool => $record->activeSubstituteCoverages()
                ->whereNotNull('substitute_teacher_id')->exists() && ! $record->isCompletedAt())
            ->schema([
                Select::make('coverage_id')
                    ->label('Teacher Coverage')
                    ->options(fn (Event $record): array => self::coverageOptions($record, confirmedOnly: true))
                    ->required(),
                self::reasonField(),
            ])
            ->modalDescription('The teacher will lose access immediately and the event will remain marked as needing a substitute.')
            ->requiresConfirmation()
            ->action(fn (array $data): mixed => self::run(
                fn (User $user): EventSubstituteCoverage => app(ManageEventSubstitution::class)->removeCurrent(
                    EventSubstituteCoverage::query()->findOrFail($data['coverage_id']),
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
            ->visible(fn (Event $record): bool => $record->activeSubstituteCoverages()->exists() && ! $record->isCompletedAt())
            ->schema([
                Select::make('coverage_id')
                    ->label('Teacher Coverage')
                    ->options(fn (Event $record): array => self::coverageOptions($record))
                    ->required(),
                self::reasonField(),
            ])
            ->requiresConfirmation()
            ->action(fn (array $data): mixed => self::run(
                fn (User $user): EventSubstituteCoverage => app(ManageEventSubstitution::class)->removeCurrent(
                    EventSubstituteCoverage::query()->findOrFail($data['coverage_id']),
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
                Select::make('covered_teacher_id')
                    ->label('Teacher Covered')
                    ->options(fn (Event $record): array => self::regularTeacherOptions($record))
                    ->required(),
                Select::make('teacher_id')
                    ->label('Teacher Who Substituted')
                    ->placeholder('No substitute')
                    ->options(self::teacherOptions(...))
                    ->searchable()
                    ->preload(),
                self::reasonField('Audit Reason'),
            ])
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
                        User::query()->findOrFail($data['covered_teacher_id']),
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

    /** @return array<int, string> */
    private static function substituteOptions(Event $event): array
    {
        $availability = app(TeacherScheduleConflictService::class);

        return $availability->availableSubstituteTeachers($event)
            ->mapWithKeys(fn (User $teacher): array => [$teacher->id => $teacher->fullName])
            ->all();
    }

    /** @return array<int, string> */
    private static function regularTeacherOptions(Event $event, bool $excludeActive = false): array
    {
        $query = $event->teachers();

        if ($excludeActive) {
            $query->whereNotIn('users.id', $event->activeSubstituteCoverages()
                ->whereNotNull('covered_teacher_id')
                ->pluck('covered_teacher_id'));
        }

        return $query->get()
            ->mapWithKeys(fn (User $teacher): array => [$teacher->id => $teacher->fullName])
            ->all();
    }

    /** @return array<int, string> */
    private static function pendingRequestOptions(Event $event): array
    {
        return $event->substituteRequests()
            ->pending()
            ->with(['teacher', 'coverage.coveredTeacher'])
            ->get()
            ->mapWithKeys(fn (EventSubstituteRequest $request): array => [
                $request->id => ($request->event_substitute_coverage_id !== null
                    ? $request->coverage->coveredTeacherName()
                    : 'Original teacher not recorded')
                    .' → '.($request->teacher_id !== null ? $request->teacher->fullName : 'Unknown teacher'),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private static function coverageOptions(
        Event $event,
        bool $pendingOnly = false,
        bool $releaseOnly = false,
        bool $confirmedOnly = false,
    ): array {
        $query = $event->activeSubstituteCoverages()
            ->with(['coveredTeacher', 'substituteTeacher', 'requests.teacher']);

        if ($pendingOnly) {
            $query->whereHas('requests', fn (Builder $query): Builder => $query
                ->where('status', \App\Enums\EventSubstituteRequestStatus::Pending));
        }

        if ($releaseOnly) {
            $query->whereHas('requests', fn (Builder $query): Builder => $query
                ->where('status', \App\Enums\EventSubstituteRequestStatus::Accepted)
                ->whereNotNull('release_requested_at'));
        }

        if ($confirmedOnly) {
            $query->whereNotNull('substitute_teacher_id');
        }

        return $query->get()
            ->mapWithKeys(fn (EventSubstituteCoverage $coverage): array => [
                $coverage->id => self::coverageOptionLabel($coverage),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private static function coverageOptionsForCurrentSubstitute(Event $event): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return $event->activeSubstituteCoverages()
            ->where('substitute_teacher_id', $user->id)
            ->with(['coveredTeacher', 'substituteTeacher', 'requests'])
            ->get()
            ->filter(fn (EventSubstituteCoverage $coverage): bool => ! $coverage->currentSubstituteRequest()?->hasReleaseRequest())
            ->mapWithKeys(fn (EventSubstituteCoverage $coverage): array => [
                $coverage->id => $coverage->coveredTeacherName(),
            ])
            ->all();
    }

    private static function coverageOptionLabel(EventSubstituteCoverage $coverage): string
    {
        $coveredTeacher = $coverage->coveredTeacherName();
        $pendingRequest = $coverage->pendingRequest();
        $replacement = $coverage->substituteTeacherName()
            ?? ($pendingRequest?->teacher_id !== null ? $pendingRequest->teacher->fullName : null)
            ?? 'Uncovered';

        return $coveredTeacher.' → '.$replacement;
    }

    private static function reasonField(string $label = 'Reason'): Textarea
    {
        return Textarea::make('reason')
            ->label($label)
            ->required()
            ->maxLength(2000);
    }

    /** @param array<string, mixed> $data */
    private static function substituteRequestReason(array $data): ?string
    {
        $reasonType = self::substituteRequestReasonType($data['reason_type'] ?? null);

        if ($reasonType === EventSubstituteRequestReason::Sick) {
            return $reasonType->getLabel();
        }

        if ($reasonType !== EventSubstituteRequestReason::Other) {
            return null;
        }

        $reason = is_string($data['reason'] ?? null) ? mb_trim($data['reason']) : '';

        return $reason !== '' ? $reason : $reasonType->getLabel();
    }

    private static function substituteRequestReasonType(mixed $reasonType): ?EventSubstituteRequestReason
    {
        if ($reasonType instanceof EventSubstituteRequestReason) {
            return $reasonType;
        }

        return is_string($reasonType) ? EventSubstituteRequestReason::tryFrom($reasonType) : null;
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
        return 'Substitute: '.($event?->substituteCoverageLabel() ?? EventSubstituteCoverageStatus::NotNeeded->getLabel());
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
