<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Boards;

use App\Filament\Admin\Resources\Boards\Pages\BoardKanban;
use App\Filament\Admin\Resources\Boards\Pages\BoardLanding;
use App\Filament\Admin\Resources\Boards\Schemas\BoardForm;
use App\Models\Board;
use App\Models\User;
use App\Services\Boards\BoardTemplateService;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

final class BoardResource extends Resource
{
    protected static ?string $model = Board::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Tools;

    protected static ?int $navigationSort = AdminNavigation::ToolsBoards;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return BoardForm::configure($schema);
    }

    /** @return Builder<Board> */
    public static function getEloquentQuery(): Builder
    {
        $query = Board::query();
        $user = auth()->user();

        return $user instanceof User
            ? $query->accessibleTo($user)
            : $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => BoardLanding::route('/'),
            'board' => BoardKanban::route('/{record}'),
        ];
    }

    public static function createBoardAction(string $name = 'createBoard'): CreateAction
    {
        return CreateAction::make($name)
            ->label('New Board')
            ->icon(Heroicon::OutlinedPlus)
            ->model(Board::class)
            ->schema(fn (Schema $schema): Schema => self::form($schema))
            ->createAnother(false)
            ->visible(fn (): bool => Gate::allows('create', Board::class))
            ->using(function (array $data): Board {
                Gate::authorize('create', Board::class);
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                return app(BoardTemplateService::class)->create($data, $user);
            })
            ->successRedirectUrl(fn (Board $record): string => self::getUrl('board', ['record' => $record]));
    }
}
