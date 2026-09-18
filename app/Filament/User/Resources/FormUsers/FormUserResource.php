<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers;

use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Filament\User\Resources\FormUsers\Pages\ViewFormUser;
use App\Filament\User\Resources\FormUsers\Tables\FormUsersTable;
use App\Models\FormAssignment;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class FormUserResource extends Resource
{
    protected static ?string $slug = 'my-forms';

    protected static ?string $model = FormAssignment::class;

    protected static bool $isGloballySearchable = false;

    protected static ?string $modelLabel = 'My Form';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        FormAssignment::applyAccessibleConstraint($query, $user);

        return $query;
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof FormAssignment && Gate::allows('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof FormAssignment && Gate::allows('update', $record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return FormUsersTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $count = FormAssignment::query()
            ->accessibleBy($user)
            ->pending()
            ->formIsActive()
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFormUsers::route('/'),
            'view' => ViewFormUser::route('/{record}'),
            'edit' => EditFormUser::route('/{record}/sign'),
            'revise' => EditFormUser::route('/{record}/revise'),
        ];
    }
}
