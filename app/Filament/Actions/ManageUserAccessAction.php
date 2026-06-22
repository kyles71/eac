<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Filament\Shared\Forms\Components\PermissionCheckboxList;
use App\Models\User;
use App\Services\AccessManagerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class ManageUserAccessAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Manage Access')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->authorize('manageAccess')
            ->modalHeading(fn (User $record): string => 'Manage access for '.$record->getFilamentName())
            ->fillForm(fn (User $record): array => [
                'roles' => $record->roles()->pluck('roles.id')->all(),
                'permissions' => $record->permissions()
                    ->whereIn('permissions.id', $this->manageablePermissionIds())
                    ->pluck('permissions.id')
                    ->all(),
            ])
            ->schema([
                CheckboxList::make('roles')
                    ->options(fn (): array => app(AccessManagerService::class)
                        ->manageableRoles($this->actor())
                        ->mapWithKeys(fn ($role): array => [
                            $role->id => "{$role->name} ({$role->weight})",
                        ])
                        ->all())
                    ->searchable()
                    ->bulkToggleable()
                    ->columns(2),
                PermissionCheckboxList::make('permissions')
                    ->label('Direct Permissions')
                    ->descriptionAboveSearch('These permissions apply in addition to permissions inherited from roles.')
                    ->options(fn (): array => app(AccessManagerService::class)
                        ->manageablePermissions($this->actor())
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->bulkToggleable()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ]),
                TextEntry::make('protected_permissions')
                    ->label('Protected direct permissions')
                    ->state(fn (User $record): array => $this->protectedPermissionNames($record))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->visible(fn (User $record): bool => $this->protectedPermissionNames($record) !== []),
            ])
            ->action(function (User $record, array $data): void {
                app(AccessManagerService::class)->syncUserAccess(
                    actor: $this->actor(),
                    target: $record,
                    roleIds: array_values($data['roles'] ?? []),
                    permissionIds: array_values($data['permissions'] ?? []),
                );

                Notification::make()
                    ->title('User access updated')
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'manageAccess';
    }

    private function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /** @return list<int|string> */
    private function manageablePermissionIds(): array
    {
        return app(AccessManagerService::class)
            ->manageablePermissions($this->actor())
            ->modelKeys();
    }

    /** @return list<string> */
    private function protectedPermissionNames(User $record): array
    {
        return $record->permissions()
            ->whereNotIn('permissions.id', $this->manageablePermissionIds())
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
