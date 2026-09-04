<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Boards\Pages;

use App\Filament\Admin\Resources\Boards\BoardResource;
use App\Models\Board;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;

final class BoardLanding extends Page
{
    protected static string $resource = BoardResource::class;

    protected string $view = 'filament.admin.resources.boards.pages.board-landing';

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $board = $this->preferredBoard($user);

        if ($board instanceof Board) {
            $this->redirect(BoardResource::getUrl('board', ['record' => $board]), navigate: true);
        }
    }

    public function getTitle(): string
    {
        return 'Boards';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            BoardResource::createBoardAction(),
        ];
    }

    private function preferredBoard(User $user): ?Board
    {
        $accessibleBoards = BoardResource::getEloquentQuery();

        $lastViewedBoardId = $user->getRawOriginal('last_viewed_board_id');

        if ($lastViewedBoardId !== null) {
            $lastViewedBoard = (clone $accessibleBoards)->find((int) $lastViewedBoardId);

            if ($lastViewedBoard instanceof Board) {
                return $lastViewedBoard;
            }
        }

        return (clone $accessibleBoards)
            ->whereNull('archived_at')
            ->latest('updated_at')
            ->latest('id')
            ->first()
            ?? (clone $accessibleBoards)
                ->whereNotNull('archived_at')
                ->latest('updated_at')
                ->latest('id')
                ->first();
    }
}
