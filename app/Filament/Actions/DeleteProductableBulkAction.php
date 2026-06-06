<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use Filament\Actions\DeleteBulkAction;

final class DeleteProductableBulkAction extends DeleteBulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->authorizeIndividualRecords('delete')
            ->modalDescription(
                'Any selected items with linked products will also permanently delete those products. '
                .'Items linked to active products or products with sales will not be deleted.',
            );
    }
}
