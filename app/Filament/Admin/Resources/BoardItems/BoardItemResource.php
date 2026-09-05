<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BoardItems;

use App\Filament\Admin\Resources\BoardItems\Pages\ViewBoardItem;
use App\Filament\Admin\Resources\BoardItems\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\BoardItems\RelationManagers\CommentsRelationManager;
use App\Filament\Admin\Resources\BoardItems\Schemas\BoardItemForm;
use App\Filament\Admin\Resources\BoardItems\Schemas\BoardItemInfolist;
use App\Filament\Admin\Resources\Boards\BoardResource;
use App\Models\Board;
use App\Models\BoardItem;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class BoardItemResource extends Resource
{
    protected static ?string $model = BoardItem::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isGloballySearchable = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return BoardItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BoardItemInfolist::configure($schema);
    }

    /** @return Builder<BoardItem> */
    public static function getEloquentQuery(): Builder
    {
        $query = BoardItem::query();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn('board_id', Board::query()->accessibleTo($user)->select('id'))
            ->with(['board', 'stage', 'creator', 'assignees', 'media']);
    }

    public static function getRelations(): array
    {
        return [
            CommentsRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getIndexUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
    ): string {
        return BoardResource::getUrl(
            'index',
            $parameters,
            $isAbsolute,
            $panel,
            $tenant,
            $shouldGuessMissingParameters,
        );
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewBoardItem::route('/{record}'),
        ];
    }
}
