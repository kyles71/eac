<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers;

use App\Enums\FormTypes;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Filament\User\Resources\FormUsers\Pages\ViewFormUser;
use App\Filament\User\Resources\FormUsers\Schemas\FormUserForm;
use App\Filament\User\Resources\FormUsers\Schemas\FormUserInfolist;
use App\Filament\User\Resources\FormUsers\Tables\FormUsersTable;
use App\Models\FormUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class FormUserResource extends Resource
{
    protected static ?string $slug = 'my-forms';

    protected static ?string $model = FormUser::class;

    protected static bool $isGloballySearchable = false;

    protected static ?string $modelLabel = 'My Form';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // protected static ?string $recordTitleAttribute = 'form.name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id());
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return auth()->check()
            ? Response::allow()
            : Response::deny();
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return self::ownsFormUser($record)
            ? Response::allow()
            : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return self::canEditFormUser($record)
            ? Response::allow()
            : Response::deny();
    }

    public static function form(Schema $schema, ?FormTypes $form_type = null): Schema
    {
        return FormUserForm::configure($schema, $form_type);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FormUserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FormUsersTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = FormUser::query()
            ->where('user_id', auth()->id())
            ->pending()
            ->whereHas('form', fn ($query) => $query->isActive())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
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
            'revise' => Pages\ReviseFormUser::route('/{record}/revise'),
        ];
    }

    private static function ownsFormUser(Model $record): bool
    {
        return $record instanceof FormUser
            && $record->user_id === auth()->id();
    }

    private static function canEditFormUser(Model $record): bool
    {
        if (! self::ownsFormUser($record)) {
            return false;
        }

        $record->loadMissing('form');

        return $record->form->form_type !== FormTypes::StudentWaiver
            || ! $record->isCompleted();
    }
}
