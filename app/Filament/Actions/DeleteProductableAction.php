<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Contracts\Productable;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

final class DeleteProductableAction extends DeleteAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->modalDescription(function (Model&Productable $record): ?string {
            $productCount = Product::query()->forProductable($record)->count();

            if ($productCount === 0) {
                return null;
            }

            return $productCount === 1
                ? 'This item has a linked product. Deleting it will also permanently delete the linked product.'
                : "This item has {$productCount} linked products. Deleting it will also permanently delete all linked products.";
        });
    }
}
