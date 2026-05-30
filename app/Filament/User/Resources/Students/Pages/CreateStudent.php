<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\Students\Pages;

use App\Filament\User\Resources\Students\StudentResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
