<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Models\User;
use App\Services\AccessManagerService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $permissionIds = array_values($data['permission_ids'] ?? []);
        unset($data['permission_ids']);

        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(AccessManagerService::class)->createRole($actor, $data, $permissionIds);
    }
}
