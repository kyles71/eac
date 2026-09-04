<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Boards\Pages;

use App\Enums\BoardItemPriority;
use App\Enums\BoardStageKind;
use App\Filament\Admin\Resources\BoardItems\BoardItemResource;
use App\Filament\Admin\Resources\BoardItems\Schemas\BoardItemForm;
use App\Filament\Admin\Resources\Boards\BoardResource;
use App\Filament\Admin\Resources\Boards\Schemas\BoardForm;
use App\Filament\Admin\Resources\Boards\Schemas\BoardMembershipForm;
use App\Filament\Admin\Resources\Boards\Schemas\BoardStageForm;
use App\Models\Board as BoardModel;
use App\Models\BoardItem;
use App\Models\BoardStage;
use App\Models\User;
use App\Services\Boards\BoardItemWorkflowService;
use App\Services\Boards\BoardMembershipService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\BoardResourcePage;
use Relaticle\Flowforge\Column;

final class BoardKanban extends BoardResourcePage
{
    use InteractsWithRecord;

    protected static string $resource = BoardResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        Gate::authorize('view', $this->record);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        User::query()->whereKey($user->id)->update(['last_viewed_board_id' => $this->boardRecord()->id]);
        $user->last_viewed_board_id = $this->boardRecord()->id;
    }

    public function board(Board $board): Board
    {
        $canManageWorkflow = Gate::allows('manageWorkflow', $this->boardRecord());
        $canManageStages = $this->canManageStages();

        return $board
            ->query($this->getEloquentQuery())
            ->recordTitleAttribute('title')
            ->columnIdentifier('board_stage_id')
            ->positionIdentifier('position')
            ->columns($this->boardRecord()->activeStages->map(
                fn (BoardStage $stage): Column => Column::make((string) $stage->id)
                    ->label($stage->name)
                    ->color($stage->color),
            )->all())
            ->cardSchema(fn (Schema $schema): Schema => $schema->components([
                Grid::make(2)
                    ->schema([
                        TextEntry::make('assignees.full_name')
                            ->label('Assigned')
                            ->placeholder('Unassigned')
                            ->listWithLineBreaks()
                            ->icon(Heroicon::OutlinedUserGroup)
                            ->columnSpan(1),
                        TextEntry::make('due_date')
                            ->date()
                            ->placeholder('No due date')
                            ->icon(Heroicon::OutlinedCalendar)
                            ->columnSpan(1),
                    ])
                    ->columnSpanFull(),
            ]))
            ->searchable(['title', 'description'])
            ->filters([
                SelectFilter::make('type')
                    ->options($this->boardRecord()->itemTypeOptions()),
                SelectFilter::make('priority')
                    ->options(BoardItemPriority::class),
                SelectFilter::make('assignee')
                    ->relationship('assignees', 'first_name')
                    ->searchable(['first_name', 'last_name'])
                    ->preload(),
                TernaryFilter::make('overdue')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereDate('due_date', '<', today()),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                            ->whereNull('due_date')
                            ->orWhereDate('due_date', '>=', today())),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                $this->createCardAction('submit')
                    ->label('Submit idea or issue')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(! $canManageWorkflow && Gate::allows('createItem', $this->boardRecord())),
            ])
            ->columnActions([
                $this->createCardAction('addCard')
                    ->label('Add card')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible($canManageWorkflow),
                $this->editStageAction()
                    ->visible($canManageStages),
                $this->retireStageAction()
                    ->visible($canManageStages),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (BoardItem $record): string => BoardItemResource::getUrl('view', ['record' => $record])),
            ])
            ->cardAction('view');
    }

    public function getTitle(): string
    {
        return $this->boardRecord()->name;
    }

    public function getSubheading(): ?string
    {
        $description = $this->boardRecord()->description;

        if (! $this->boardRecord()->isArchived()) {
            return $description;
        }

        return filled($description)
            ? 'Archived — read only. '.$description
            : 'Archived — read only.';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** @return Builder<BoardItem> */
    public function getEloquentQuery(): Builder
    {
        return BoardItem::query()
            ->where('board_id', $this->boardRecord()->id)
            ->whereNull('archived_at')
            ->with(['assignees', 'stage'])
            ->withCount('comments');
    }

    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null,
    ): void {
        $item = $this->getEloquentQuery()->findOrFail($cardId);
        Gate::authorize('move', $item);
        $fromStage = $item->stage;
        $toStage = $this->boardRecord()->activeStages()->findOrFail((int) $targetColumnId);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        DB::transaction(function () use ($cardId, $targetColumnId, $afterCardId, $beforeCardId, $item, $fromStage, $toStage, $user): void {
            parent::moveCard($cardId, $targetColumnId, $afterCardId, $beforeCardId);
            app(BoardItemWorkflowService::class)->recordStageChange($item->refresh(), $fromStage, $toStage, $user);
        });
    }

    /** @param array<int, int|string> $stageIds */
    public function reorderStages(array $stageIds): void
    {
        Gate::authorize('update', $this->boardRecord());

        $submittedIds = collect($stageIds)->map(fn (int|string $id): int => (int) $id)->values();
        $activeIds = $this->boardRecord()->activeStages()->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();

        if ($submittedIds->duplicates()->isNotEmpty() || $submittedIds->sort()->values()->all() !== $activeIds->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'stageOrder' => 'The submitted stage order does not match this board.',
            ]);
        }

        DB::transaction(function () use ($submittedIds): void {
            foreach ($submittedIds as $index => $stageId) {
                $this->boardRecord()->activeStages()->whereKey($stageId)->update([
                    'sort_order' => ($index + 1) * 10,
                ]);
            }
        });

        $this->boardRecord()->unsetRelation('activeStages');
        $this->dispatch('board-stages-reordered');
    }

    public function canManageStages(): bool
    {
        return Gate::allows('update', $this->boardRecord());
    }

    public function canMoveCards(): bool
    {
        return Gate::allows('manageWorkflow', $this->boardRecord());
    }

    public function boardWorkspaceId(): int
    {
        return $this->boardRecord()->id;
    }

    public function addStageAction(): CreateAction
    {
        return CreateAction::make('addStage')
            ->label('Add stage')
            ->icon(Heroicon::OutlinedPlus)
            ->extraAttributes(['class' => 'w-full justify-center'])
            ->model(BoardStage::class)
            ->schema(BoardStageForm::components())
            ->visible($this->canManageStages())
            ->using(function (array $data): BoardStage {
                Gate::authorize('update', $this->boardRecord());
                $validated = $this->validateStageData($data);

                return DB::transaction(function () use ($validated): BoardStage {
                    $isDefault = $validated['is_default']
                        || ! $this->boardRecord()->activeStages()->where('is_default', true)->exists();

                    if ($isDefault) {
                        $this->boardRecord()->stages()->update(['is_default' => false]);
                    }

                    return $this->boardRecord()->stages()->create([
                        ...$validated,
                        'sort_order' => ((int) $this->boardRecord()->stages()->max('sort_order')) + 10,
                        'is_default' => $isDefault,
                        'archived_at' => null,
                    ]);
                });
            })
            ->successNotificationTitle('Stage created');
    }

    /** @return array<Action|CreateAction> */
    protected function getHeaderActions(): array
    {
        return [
            $this->switchBoardAction(),
            BoardResource::createBoardAction(),
            $this->membersAction(),
            $this->settingsAction(),
        ];
    }

    private function switchBoardAction(): Action
    {
        return Action::make('switchBoard')
            ->label('Switch Board')
            ->icon(Heroicon::OutlinedViewColumns)
            ->visible(fn (): bool => BoardResource::getEloquentQuery()
                ->whereKeyNot($this->boardRecord()->id)
                ->exists())
            ->fillForm(fn (): array => ['board_id' => $this->boardRecord()->id])
            ->schema([
                Select::make('board_id')
                    ->label('Board')
                    ->options(fn (): array => $this->boardOptions())
                    ->searchable()
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->modalHeading('Switch board')
            ->modalSubmitActionLabel('Open board')
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->action(function (array $data): void {
                $board = BoardResource::getEloquentQuery()->findOrFail((int) $data['board_id']);
                Gate::authorize('view', $board);
                $this->redirect(BoardResource::getUrl('board', ['record' => $board]), navigate: true);
            });
    }

    private function membersAction(): Action
    {
        return Action::make('manageMembers')
            ->label('Board members')
            ->icon(Heroicon::OutlinedUserGroup)
            ->iconButton()
            ->tooltip('Board members')
            ->badge(fn (): int => $this->boardRecord()->memberships()->count())
            ->visible(fn (): bool => Gate::allows('manageMembers', $this->boardRecord()))
            ->fillForm(fn (): array => [
                'memberships' => $this->boardRecord()->memberships()
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($membership): array => [
                        'user_id' => $membership->user_id,
                        'role' => $membership->role->value,
                    ])
                    ->all(),
            ])
            ->schema([
                BoardMembershipForm::make($this->boardRecord()),
            ])
            ->slideOver()
            ->modalHeading('Board members')
            ->modalSubmitActionLabel('Save members')
            ->action(function (array $data): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                try {
                    app(BoardMembershipService::class)->sync(
                        $this->boardRecord(),
                        $user,
                        $data['memberships'] ?? [],
                    );
                } catch (ValidationException $exception) {
                    $actionIndex = array_key_last($this->mountedActions);

                    throw ValidationException::withMessages([
                        "mountedActions.{$actionIndex}.data.memberships" => $exception->errors()['memberships'] ?? ['The board members could not be updated.'],
                    ]);
                }

                Notification::make()->title('Board members updated')->success()->send();

                if (Gate::forUser($user)->denies('view', $this->boardRecord())) {
                    $this->redirect(BoardResource::getUrl('index'), navigate: true);
                }
            });
    }

    private function settingsAction(): Action
    {
        $board = $this->boardRecord();

        return Action::make('boardSettings')
            ->label('Board settings')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->iconButton()
            ->tooltip('Board settings')
            ->visible(fn (): bool => Gate::allows('update', $board)
                || Gate::allows('archive', $board)
                || Gate::allows('restore', $board))
            ->fillForm(fn (): array => [
                'name' => $board->name,
                'description' => $board->description,
                'interaction_mode' => $board->interaction_mode->value,
                'allowed_item_types' => $board->allowed_item_types,
            ])
            ->schema(BoardForm::settingsComponents($board->isArchived()))
            ->slideOver()
            ->modalHeading('Board settings')
            ->modalSubmitAction(fn (Action $action): Action|false => $board->isArchived() ? false : $action)
            ->modalSubmitActionLabel('Save settings')
            ->extraModalFooterActions([
                Action::make('archiveBoard')
                    ->label('Archive board')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('archive', $board))
                    ->action(function () use ($board): void {
                        Gate::authorize('archive', $board);
                        $board->update(['archived_at' => now()]);
                        Notification::make()->title('Board archived')->success()->send();
                        $this->redirect(BoardResource::getUrl('board', ['record' => $board]), navigate: true);
                    })
                    ->cancelParentActions(),
                Action::make('restoreBoard')
                    ->label('Restore board')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('restore', $board))
                    ->action(function () use ($board): void {
                        Gate::authorize('restore', $board);
                        $board->update(['archived_at' => null]);
                        Notification::make()->title('Board restored')->success()->send();
                        $this->redirect(BoardResource::getUrl('board', ['record' => $board]), navigate: true);
                    })
                    ->cancelParentActions(),
            ])
            ->action(function (array $data) use ($board): void {
                Gate::authorize('update', $board);
                $data['name'] = mb_trim((string) $data['name']);
                $board->update(Arr::only($data, [
                    'name',
                    'description',
                    'interaction_mode',
                    'allowed_item_types',
                ]));
                $this->record = $board->refresh();
                Notification::make()->title('Board settings updated')->success()->send();
            });
    }

    private function editStageAction(): Action
    {
        return Action::make('editStage')
            ->label('Edit stage')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->fillForm(function (array $arguments): array {
                $stage = $this->activeStageFromArguments($arguments);

                return [
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'kind' => $stage->kind->value,
                ];
            })
            ->schema(BoardStageForm::components())
            ->action(function (array $data, array $arguments): void {
                $stage = $this->activeStageFromArguments($arguments);
                Gate::authorize('update', $stage);
                $validated = $this->validateStageData($data, $stage);

                DB::transaction(function () use ($stage, $validated): void {
                    $isDefault = $stage->is_default || $validated['is_default'];

                    if ($isDefault) {
                        $stage->board->stages()->whereKeyNot($stage->id)->update(['is_default' => false]);
                    }

                    $stage->update([
                        ...$validated,
                        'is_default' => $isDefault,
                    ]);
                });

                Notification::make()->title('Stage updated')->success()->send();
            });
    }

    private function retireStageAction(): Action
    {
        return Action::make('retireStage')
            ->label('Retire stage')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                Select::make('replacement_stage_id')
                    ->label('Move cards to')
                    ->options(fn (): array => $this->boardRecord()->activeStages()->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->disabled(function (array $arguments): bool {
                $stage = $this->stageFromArguments($arguments);

                return $stage->archived_at !== null || $stage->board->activeStages()->count() <= 1;
            })
            ->action(function (array $data, array $arguments): void {
                $stage = $this->activeStageFromArguments($arguments);
                $replacement = $stage->board->activeStages()->findOrFail((int) $data['replacement_stage_id']);
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                app(BoardItemWorkflowService::class)->retireStage($stage, $replacement, $user);
                Notification::make()->title('Stage retired')->success()->send();
            });
    }

    /** @return array<string, array<int, string>> */
    private function boardOptions(): array
    {
        $boards = BoardResource::getEloquentQuery()
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return collect([
            'Active boards' => $boards->whereNull('archived_at')->pluck('name', 'id')->all(),
            'Archived boards' => $boards->whereNotNull('archived_at')->pluck('name', 'id')->all(),
        ])->filter()->all();
    }

    private function boardRecord(): BoardModel
    {
        /** @var BoardModel $record */
        $record = $this->getRecord();

        return $record;
    }

    private function createCardAction(string $name): CreateAction
    {
        return CreateAction::make($name)
            ->model(BoardItem::class)
            ->schema(BoardItemForm::creationComponents($this->boardRecord()))
            ->using(function (array $data, array $arguments): BoardItem {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                $stageId = (int) ($arguments['column'] ?? $this->boardRecord()->defaultStage()?->id);
                $stage = $this->boardRecord()->activeStages()->findOrFail($stageId);

                return app(BoardItemWorkflowService::class)->create($this->boardRecord(), $stage, $user, $data);
            })
            ->after(function (): void {
                $this->forceRender();
            });
    }

    /** @param array<string, mixed> $arguments */
    private function stageFromArguments(array $arguments): BoardStage
    {
        return $this->boardRecord()->stages()->findOrFail((int) ($arguments['column'] ?? 0));
    }

    /** @param array<string, mixed> $arguments */
    private function activeStageFromArguments(array $arguments): BoardStage
    {
        return $this->boardRecord()->activeStages()->findOrFail((int) ($arguments['column'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, color: string, kind: BoardStageKind, is_default: bool}
     */
    private function validateStageData(array $data, ?BoardStage $stage = null): array
    {
        $validated = Validator::make($data, [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('board_stages', 'name')
                    ->where('board_id', $this->boardRecord()->id)
                    ->ignore($stage?->id),
            ],
            'color' => ['required', Rule::in(array_keys(BoardForm::colorOptions()))],
            'kind' => ['required', Rule::enum(BoardStageKind::class)],
            'is_default' => ['boolean'],
        ])->validate();

        return [
            'name' => mb_trim((string) $validated['name']),
            'color' => (string) $validated['color'],
            'kind' => $validated['kind'] instanceof BoardStageKind
                ? $validated['kind']
                : BoardStageKind::from((string) $validated['kind']),
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ];
    }
}
