<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BoardItems\Pages;

use App\Filament\Admin\Resources\BoardItems\BoardItemResource;
use App\Filament\Admin\Resources\Boards\BoardResource;
use App\Models\BoardItem;
use App\Models\User;
use App\Services\Boards\BoardItemWorkflowService;
use App\Services\Boards\BoardNotificationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

final class ViewBoardItem extends ViewRecord
{
    protected static string $resource = BoardItemResource::class;

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        /** @var BoardItem $item */
        $item = $this->getRecord();

        return [
            Action::make('backToBoard')
                ->label('Back to board')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(BoardResource::getUrl('board', ['record' => $item->board])),
            $this->watchAction($item),
            EditAction::make()
                ->visible(Gate::allows('update', $item)),
            Action::make('archive')
                ->icon('heroicon-o-archive-box')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(! $item->isArchived() && Gate::allows('archive', $item))
                ->action(function () use ($item): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    app(BoardItemWorkflowService::class)->archive($item, $user);
                    $this->refreshFormData(['archived_at']);
                }),
            Action::make('restore')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success')
                ->visible($item->isArchived() && Gate::allows('archive', $item))
                ->action(function () use ($item): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    app(BoardItemWorkflowService::class)->restore($item, $user);
                    $this->refreshFormData(['archived_at']);
                }),
        ];
    }

    private function watchAction(BoardItem $item): Action
    {
        return Action::make('watch')
            ->label(fn (): string => $this->isWatching($item) ? 'Unwatch' : 'Watch')
            ->icon(fn (): string => $this->isWatching($item) ? 'heroicon-o-bell-slash' : 'heroicon-o-bell')
            ->color('gray')
            ->action(function () use ($item): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                $service = app(BoardNotificationService::class);

                $this->isWatching($item) ? $service->unwatch($item, $user) : $service->watch($item, $user);
                $this->forceRender();
            });
    }

    private function isWatching(BoardItem $item): bool
    {
        $user = auth()->user();

        return $user instanceof User && $item->subscriptions()
            ->where('user_id', $user->id)
            ->whereNull('muted_at')
            ->exists();
    }
}
