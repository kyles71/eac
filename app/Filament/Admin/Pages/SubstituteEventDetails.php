<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Events\ManageEventSubstitution;
use App\Filament\Tables\Columns\AttendanceRadioColumn;
use App\Models\Course;
use App\Models\Event;
use App\Models\User;
use App\Services\EventAttendanceService;
use App\Support\MediaDisks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class SubstituteEventDetails extends Page implements HasTable
{
    use InteractsWithTable;

    public ?Event $event = null;

    protected static ?string $slug = 'substitute-events/{event}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = null;

    public function mount(Event $event): void
    {
        Gate::authorize('viewSubstituteDetails', $event);
        $event->loadMissing(['calendar', 'course', 'media', 'substituteTeacher']);
        $this->event = $event;
        $this->heading = $event->name;
        $this->subheading = 'Substitute event details and attendance';
    }

    public function getTitle(): string
    {
        return $this->event instanceof Event ? $this->event->name : 'Substitute Event';
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->event instanceof Event) {
            return $schema->components([]);
        }

        return $schema->components([
            Section::make('Event Details')
                ->columns(2)
                ->schema([
                    TextEntry::make('course')
                        ->state($this->event->course instanceof Course ? $this->event->course->name : null)
                        ->placeholder('Standalone event'),
                    TextEntry::make('calendar')
                        ->state($this->event->calendar?->name)
                        ->placeholder('None'),
                    TextEntry::make('starts_at')
                        ->state($this->event->start_time)
                        ->dateTime(),
                    TextEntry::make('ends_at')
                        ->state($this->event->end_time)
                        ->dateTime(),
                    TextEntry::make('focus')
                        ->state($this->event->focus)
                        ->placeholder('None'),
                    TextEntry::make('description')
                        ->state($this->event->description)
                        ->placeholder('No public description was provided.')
                        ->columnSpanFull(),
                    TextEntry::make('lesson_plan')
                        ->label('Lesson Plan')
                        ->state($this->event->details)
                        ->placeholder('No lesson plan was provided.')
                        ->columnSpanFull(),
                ]),
            Section::make('Media')
                ->schema([
                    SpatieMediaLibraryImageEntry::make('images')
                        ->collection('images')
                        ->disk(MediaDisks::public())
                        ->visibility('public')
                        ->columnSpanFull(),
                    View::make('filament.admin.pages.substitute-event-documents')
                        ->viewData(['documents' => $this->documents()]),
                ])
                ->visible($this->event->getMedia('images')->isNotEmpty() || $this->event->getMedia('documents')->isNotEmpty()),
            Section::make('Cancellation')
                ->visible($this->event->isCancelled())
                ->schema([
                    TextEntry::make('cancelled_at')
                        ->state($this->event->cancelled_at)
                        ->dateTime(),
                    TextEntry::make('cancellation_reason')
                        ->state($this->event->cancellation_reason),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->attendance()->eventRosterQuery($this->eventRecord()))
            ->heading('Attendance')
            ->columns([
                TextColumn::make('student_name')
                    ->label('Student')
                    ->state(fn (Model $record): string => $this->attendance()->recordStudentName($record)),
                AttendanceRadioColumn::make('attendance_status')
                    ->label('Attendance')
                    ->disabled(fn (): bool => Gate::denies('recordSubstituteAttendance', $this->eventRecord()))
                    ->state(fn (Model $record): ?string => $this->attendance()
                        ->recordStudentAttendanceStatus($this->eventRecord(), $record))
                    ->updateStateUsing(function (Model $record, mixed $state): ?string {
                        Gate::authorize('recordSubstituteAttendance', $this->eventRecord());

                        return $this->attendance()->setRecordStudentAttendanceStatus($this->eventRecord(), $record, $state);
                    }),
                TextInputColumn::make('notes')
                    ->disabled(fn (): bool => Gate::denies('recordSubstituteAttendance', $this->eventRecord()))
                    ->state(fn (Model $record): ?string => $this->attendance()->recordStudentNotes($this->eventRecord(), $record))
                    ->updateStateUsing(function (Model $record, mixed $state): ?string {
                        Gate::authorize('recordSubstituteAttendance', $this->eventRecord());

                        return $this->attendance()->setRecordStudentNotes($this->eventRecord(), $record, $state);
                    }),
            ])
            ->paginated(false);
    }

    /** @return list<array{name: string, url: string}> */
    public function documents(): array
    {
        if (! $this->event instanceof Event) {
            return [];
        }

        Gate::authorize('viewSubstituteDetails', $this->event);
        $expiresAt = now()->addMinutes((int) config('filament.temporary_file_url_expiry_minutes', 30));

        return $this->event->getMedia('documents')
            ->map(fn (Media $media): array => [
                'name' => $media->name,
                'url' => $media->getTemporaryUrl($expiresAt),
            ])
            ->values()
            ->all();
    }

    public function requestReleaseAction(): Action
    {
        return Action::make('requestRelease')
            ->label('I Can No Longer Cover This Event')
            ->color('danger')
            ->visible(fn (): bool => $this->event instanceof Event
                && Gate::allows('requestSubstituteRelease', $this->event)
                && ! $this->event->currentSubstituteRequest()?->hasReleaseRequest())
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(2000),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $this->event instanceof Event || ! $user instanceof User) {
                    return;
                }

                try {
                    app(ManageEventSubstitution::class)->requestRelease(
                        $this->event,
                        $user,
                        (string) ($data['reason'] ?? ''),
                    );
                    Notification::make()
                        ->title('Release requested')
                        ->body('You remain assigned until staff removes or replaces you.')
                        ->warning()
                        ->send();
                    $this->event->refresh();
                } catch (Throwable $exception) {
                    Notification::make()->title('Could not request release')->body($exception->getMessage())->danger()->send();
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->requestReleaseAction()];
    }

    private function attendance(): EventAttendanceService
    {
        return app(EventAttendanceService::class);
    }

    private function eventRecord(): Event
    {
        abort_unless($this->event instanceof Event, 404);

        return $this->event;
    }
}
