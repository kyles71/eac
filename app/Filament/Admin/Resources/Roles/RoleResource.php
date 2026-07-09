<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Roles;

use App\Filament\Admin\Resources\Roles\Pages\CreateRole;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Roles\Pages\ViewRole;
use App\Filament\Shared\Forms\Components\PermissionCheckboxList;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessManagerService;
use App\Support\Filament\AdminNavigation;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Override;
use UnitEnum;

final class RoleResource extends ShieldRoleResource
{
    #[Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return AdminNavigation::PeopleAndAccess;
    }

    #[Override]
    public static function getNavigationSort(): ?int
    {
        return AdminNavigation::PeopleRoles;
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->rules(['not_in:'.Role::SUPER_ADMIN]),
                        TextInput::make('weight')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(fn (): int => max(0, (app(AccessManagerService::class)->highestRoleWeight(self::actor()) ?? 0) - 1))
                            ->default(0)
                            ->required()
                            ->helperText('Higher weights outrank lower weights. This must remain below your highest role.'),
                        Hidden::make('guard_name')
                            ->default('web'),
                    ]),
                Section::make('Permissions')
                    ->columnSpanFull()
                    ->schema([
                        PermissionCheckboxList::make('permission_ids')
                            ->hiddenLabel()
                            ->options(fn (): array => app(AccessManagerService::class)
                                ->manageablePermissions(self::actor())
                                ->pluck('name', 'id')
                                ->all())
                            ->afterStateHydrated(function (PermissionCheckboxList $component, ?Role $record): void {
                                if (! $record instanceof Role) {
                                    $component->state([]);

                                    return;
                                }

                                $manageableIds = app(AccessManagerService::class)
                                    ->manageablePermissions(self::actor())
                                    ->modelKeys();

                                $component->state(
                                    $record->permissions()
                                        ->whereIn('permissions.id', $manageableIds)
                                        ->pluck('permissions.id')
                                        ->all(),
                                );
                            })
                            ->searchable()
                            ->bulkToggleable()
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                'xl' => 3,
                            ]),
                        TextEntry::make('protected_permissions')
                            ->label('Protected permissions')
                            ->state(fn (?Role $record): array => self::protectedPermissionNames($record))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->visible(fn (?Role $record): bool => self::protectedPermissionNames($record) !== [])
                            ->helperText('These permissions are outside your own access and will be preserved.'),
                    ]),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::Medium)
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->searchable(),
                TextColumn::make('weight')
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->badge(),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->badge(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = self::actorOrNull();
        $actorWeight = $actor instanceof User
            ? app(AccessManagerService::class)->highestRoleWeight($actor)
            : null;

        if ($actorWeight === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('name', '!=', Role::SUPER_ADMIN)
            ->where('weight', '<', $actorWeight);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    private static function actor(): User
    {
        $actor = self::actorOrNull();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private static function actorOrNull(): ?User
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User ? $actor : null;
    }

    /** @return list<string> */
    private static function protectedPermissionNames(?Role $role): array
    {
        if (! $role instanceof Role) {
            return [];
        }

        $manageableIds = app(AccessManagerService::class)
            ->manageablePermissions(self::actor())
            ->modelKeys();

        return $role->permissions()
            ->whereNotIn('permissions.id', $manageableIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
