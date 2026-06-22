<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessManagerService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Role, 404);

        $permissionIds = array_values($data['permission_ids'] ?? []);
        unset($data['permission_ids']);

        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(AccessManagerService::class)->updateRole($actor, $record, $data, $permissionIds);
    }
}
