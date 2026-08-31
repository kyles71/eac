<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Actions\IssueCreditGrantAction;
use App\Filament\Actions\ManageUserAccessAction;
use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            IssueCreditGrantAction::make(),
            ManageUserAccessAction::make()
                ->after(function (): void {
                    $this->refreshAccessDependentContent();
                }),
            EditAction::make(),
        ];
    }

    private function refreshAccessDependentContent(): void
    {
        $this->cachedRelationManagers = null;

        unset(
            $this->cachedSchemas['content'],
            $this->cachedSchemas['infolist'],
        );
    }
}
