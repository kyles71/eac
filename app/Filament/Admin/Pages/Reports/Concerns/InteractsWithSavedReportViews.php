<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports\Concerns;

use App\Enums\SavedReportViewVisibility;
use App\Models\Role;
use App\Models\SavedReportView;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

trait InteractsWithSavedReportViews
{
    protected function saveReportViewAction(): Action
    {
        return Action::make('saveReportView')
            ->label('Save View')
            ->icon('heroicon-o-bookmark')
            ->schema([
                TextInput::make('name')
                    ->maxLength(100)
                    ->required(),
                Select::make('visibility')
                    ->options(fn (): array => $this->savedViewVisibilityOptions())
                    ->default(SavedReportViewVisibility::Private->value)
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->action(function (array $data): void {
                $user = $this->reportUser();
                $visibility = SavedReportViewVisibility::tryFrom((string) $data['visibility'])
                    ?? SavedReportViewVisibility::Private;

                if (! $user->hasAnyRole([Role::OWNER, Role::SUPER_ADMIN])) {
                    $visibility = SavedReportViewVisibility::Private;
                }

                SavedReportView::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'report_key' => $this->reportKey(),
                        'name' => mb_trim((string) $data['name']),
                    ],
                    [
                        'visibility' => $visibility,
                        'state' => $this->reportViewState(),
                    ],
                );

                Notification::make()
                    ->title('Report view saved')
                    ->success()
                    ->send();
            });
    }

    protected function loadReportViewAction(): Action
    {
        return Action::make('loadReportView')
            ->label('Load View')
            ->icon('heroicon-o-folder-open')
            ->schema([
                Select::make('saved_report_view_id')
                    ->label('Saved View')
                    ->options(fn (): array => $this->savedReportViewOptions())
                    ->searchable()
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->action(function (array $data): void {
                $view = SavedReportView::query()->findOrFail((int) $data['saved_report_view_id']);
                abort_unless(
                    $view->report_key === $this->reportKey()
                    && $view->isVisibleTo($this->reportUser()),
                    403,
                );

                $this->applyReportViewState($view->state);

                Notification::make()
                    ->title("Loaded {$view->name}")
                    ->success()
                    ->send();
            });
    }

    protected function deleteReportViewAction(): Action
    {
        return Action::make('deleteReportView')
            ->label('Delete View')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                Select::make('saved_report_view_id')
                    ->label('Your Saved View')
                    ->options(fn (): array => SavedReportView::query()
                        ->where('user_id', $this->reportUser()->id)
                        ->where('report_key', $this->reportKey())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                SavedReportView::query()
                    ->where('user_id', $this->reportUser()->id)
                    ->where('report_key', $this->reportKey())
                    ->findOrFail((int) $data['saved_report_view_id'])
                    ->delete();

                Notification::make()
                    ->title('Saved view deleted')
                    ->success()
                    ->send();
            });
    }

    /** @return array<string, mixed> */
    protected function reportViewState(): array
    {
        return [
            'filters' => $this->sanitizeReportFilters($this->tableFilters ?? []),
            'search' => filled($this->tableSearch) ? mb_substr((string) $this->tableSearch, 0, 200) : null,
            'sort' => $this->sanitizeReportSort($this->tableSort),
            'columns' => $this->visibleReportColumnNames(),
        ];
    }

    /** @param array<string, mixed> $state */
    protected function applyReportViewState(array $state): void
    {
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $this->tableFilters = $this->sanitizeReportFilters($filters);
        $this->getTableFiltersForm()->fill($this->tableFilters);
        $this->tableSearch = is_string($state['search'] ?? null)
            ? mb_substr($state['search'], 0, 200)
            : '';
        $this->tableSort = $this->sanitizeReportSort(
            is_string($state['sort'] ?? null) ? $state['sort'] : null,
        );
        $this->applySavedColumnNames(
            is_array($state['columns'] ?? null)
                ? array_values(array_filter($state['columns'], is_string(...)))
                : [],
        );
        $this->resetPage();
    }

    /** @param array<string, mixed> $filters */
    private function sanitizeReportFilters(array $filters): array
    {
        return collect($filters)
            ->only($this->reportKey()->allowedFilterNames())
            ->map(function (mixed $value, string $name): mixed {
                if (! is_array($value)) {
                    return $value;
                }

                return match ($name) {
                    'academic_term_id',
                    'competition_season_id',
                    'capacity_status',
                    'course_tag',
                    'course_id',
                    'instructor_id',
                    'coverage_status' => [
                        'value' => $value['value'] ?? null,
                    ],
                    'date_range' => [
                        'from' => is_string($value['from'] ?? null) ? $value['from'] : null,
                        'through' => is_string($value['through'] ?? null) ? $value['through'] : null,
                    ],
                    default => [],
                };
            })
            ->all();
    }

    private function sanitizeReportSort(?string $sort): ?string
    {
        if (! is_string($sort) || ! preg_match('/^([^:]+):(asc|desc)$/', $sort, $matches)) {
            return null;
        }

        return $this->getTable()->getColumn($matches[1]) === null ? null : $sort;
    }

    /** @return list<string> */
    private function visibleReportColumnNames(): array
    {
        return collect($this->tableColumns)
            ->filter(fn (array $column): bool => ($column['type'] ?? null) === 'column'
                && ($column['isToggled'] ?? false)
                && ! ($column['isHidden'] ?? false))
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name))
            ->values()
            ->all();
    }

    /** @param list<string> $columnNames */
    private function applySavedColumnNames(array $columnNames): void
    {
        $defaults = collect($this->getDefaultTableColumnState())->keyBy('name');
        $columns = collect($columnNames)
            ->map(function (string $name) use ($defaults): ?array {
                $column = $defaults->get($name);

                if (! is_array($column)) {
                    return null;
                }

                $column['isToggled'] = true;

                return $column;
            })
            ->filter()
            ->values();

        foreach ($defaults as $name => $column) {
            if ($columns->contains(fn (array $saved): bool => $saved['name'] === $name)) {
                continue;
            }

            $column['isToggled'] = ! ($column['isToggleable'] ?? false)
                && ! ($column['isHidden'] ?? false);
            $columns->push($column);
        }

        $this->applyTableColumnManager($columns->all(), wasReordered: true);
    }

    /** @return array<string, array<int, string>> */
    private function savedReportViewOptions(): array
    {
        $user = $this->reportUser();
        $views = SavedReportView::query()
            ->visibleTo($user)
            ->where('report_key', $this->reportKey())
            ->with('user:id,first_name,last_name')
            ->orderBy('name')
            ->get();

        return [
            'My Views' => $views
                ->where('user_id', $user->id)
                ->pluck('name', 'id')
                ->all(),
            'Staff Templates' => $views
                ->where('visibility', SavedReportViewVisibility::Template)
                ->reject(fn (SavedReportView $view): bool => $view->user_id === $user->id)
                ->mapWithKeys(fn (SavedReportView $view): array => [
                    $view->id => $view->name.' — '.$view->user->fullName,
                ])
                ->all(),
        ];
    }

    /** @return array<string, string> */
    private function savedViewVisibilityOptions(): array
    {
        $options = [
            SavedReportViewVisibility::Private->value => SavedReportViewVisibility::Private->getLabel(),
        ];

        if ($this->reportUser()->hasAnyRole([Role::OWNER, Role::SUPER_ADMIN])) {
            $options[SavedReportViewVisibility::Template->value] = SavedReportViewVisibility::Template->getLabel();
        }

        return $options;
    }

    private function reportUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
