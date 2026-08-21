<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Productable;
use App\Models\Gear;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

final readonly class ProductAssociationService
{
    public function requiresSingularAssociation(Product $product): bool
    {
        $productableClass = $this->productableClass($product);

        return $productableClass !== null
            && ! is_a($productableClass, Gear::class, true);
    }

    public function assertSingularAssociationAvailable(Product $product): void
    {
        $productableClass = $this->productableClass($product);
        $productableId = $product->productable_id;

        if ($productableClass === null || $productableId === null) {
            return;
        }

        /** @var Model $productable */
        $productable = $productableClass::query()
            ->lockForUpdate()
            ->find($productableId);

        if (! $productable instanceof Productable) {
            throw ValidationException::withMessages([
                'productable_id' => 'The selected linked item is invalid.',
            ]);
        }

        $alreadyLinked = Product::query()
            ->where('productable_type', $product->productable_type)
            ->where('productable_id', $productableId)
            ->when(
                $product->exists,
                fn (Builder $query): Builder => $query->whereKeyNot($product->getKey()),
            )
            ->exists();

        if ($alreadyLinked) {
            throw ValidationException::withMessages([
                'productable_id' => 'The selected linked item already has a Product.',
            ]);
        }
    }

    /** @return class-string<Model>|null */
    private function productableClass(Product $product): ?string
    {
        if ($product->productable_type === null || $product->productable_id === null) {
            return null;
        }

        $productableClass = Relation::getMorphedModel($product->productable_type)
            ?? $product->productable_type;

        if (! is_a($productableClass, Model::class, true)) {
            throw ValidationException::withMessages([
                'productable_type' => 'The selected Product type is invalid.',
            ]);
        }

        return $productableClass;
    }
}
