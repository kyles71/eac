<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Events\ManageEventSubstitution;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class SubstituteRequest extends Page
{
    public ?EventSubstituteRequest $request = null;

    protected static ?string $slug = 'substitute-requests/{request}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = null;

    public function mount(EventSubstituteRequest $request): void
    {
        Gate::authorize('view', $request);
        $request->loadMissing(['event.calendar', 'event.course', 'teacher', 'requestedBy']);
        $this->request = $request;
        $this->heading = 'Substitute Request';
        $this->subheading = $request->event->name;
    }

    public function getTitle(): string
    {
        return $this->request?->event->name ?? 'Substitute Request';
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->request instanceof EventSubstituteRequest) {
            return $schema->components([]);
        }

        $request = $this->request;
        $event = $request->event;

        return $schema->components([
            Section::make('Request Details')
                ->columns(2)
                ->schema([
                    TextEntry::make('event')
                        ->state($event->name),
                    TextEntry::make('course')
                        ->state($event->course?->name)
                        ->placeholder('Standalone event'),
                    TextEntry::make('starts_at')
                        ->state($event->start_time)
                        ->dateTime(),
                    TextEntry::make('ends_at')
                        ->state($event->end_time)
                        ->dateTime(),
                    TextEntry::make('calendar')
                        ->state($event->calendar?->name)
                        ->placeholder('None'),
                    TextEntry::make('requested_by')
                        ->state($request->requestedBy?->fullName)
                        ->placeholder('Unknown'),
                    TextEntry::make('description')
                        ->state($event->description)
                        ->placeholder('No public description was provided.')
                        ->columnSpanFull(),
                    TextEntry::make('reason')
                        ->state($request->request_reason)
                        ->placeholder('No additional reason was provided.')
                        ->columnSpanFull(),
                    TextEntry::make('status')
                        ->state($request->status)
                        ->badge(),
                    Actions::make([
                        $this->acceptAction(),
                        $this->declineAction(),
                    ])
                        ->visible($request->isPending() && $event->canAcceptSubstituteRequestAt())
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('Accept Request')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('You will become the confirmed substitute and receive access to the event lesson plan, documents, roster, and attendance.')
            ->action(function (): void {
                $request = $this->request;
                $user = auth()->user();

                if (! $request instanceof EventSubstituteRequest || ! $user instanceof User) {
                    return;
                }

                try {
                    app(ManageEventSubstitution::class)->respond($request, $user, true);
                    $request->refresh();
                    Notification::make()->title('Substitute request accepted')->success()->send();
                    $this->redirect(SubstituteEventDetails::getUrl(['event' => $request->event_id], panel: 'admin'));
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not accept substitute request')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function declineAction(): Action
    {
        return Action::make('decline')
            ->label('Decline Request')
            ->color('danger')
            ->schema([
                Textarea::make('response_note')
                    ->label('Optional Note')
                    ->maxLength(2000),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $request = $this->request;
                $user = auth()->user();

                if (! $request instanceof EventSubstituteRequest || ! $user instanceof User) {
                    return;
                }

                try {
                    app(ManageEventSubstitution::class)->respond(
                        $request,
                        $user,
                        false,
                        is_string($data['response_note'] ?? null) ? $data['response_note'] : null,
                    );
                    $request->refresh();
                    Notification::make()->title('Substitute request declined')->success()->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not decline substitute request')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
