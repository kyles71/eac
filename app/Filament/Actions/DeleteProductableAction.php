<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Contracts\Productable;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

final class DeleteProductableAction extends DeleteAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->modalDescription(fn (Model&Productable $record): ?string => $record->product()->exists()
            ? 'This item has a linked product. Deleting it will also permanently delete the linked product.'
            : null);
    }
}
