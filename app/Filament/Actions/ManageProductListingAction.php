<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\Products\Schemas\ProductForm;
use App\Models\Costume;
use App\Models\Course;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class ManageProductListingAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(fn (Model $record): string => self::product($record) instanceof Product
                ? 'Edit Product Listing'
                : 'Create Product Listing')
            ->icon(fn (Model $record): Heroicon => self::product($record) instanceof Product
                ? Heroicon::OutlinedPencilSquare
                : Heroicon::OutlinedPlusCircle)
            ->authorize(fn (Model $record): bool => self::canManage($record))
            ->modalHeading(fn (Model $record): string => self::product($record) instanceof Product
                ? 'Edit Product Listing'
                : 'Create Product Listing')
            ->modalSubmitActionLabel(fn (Model $record): string => self::product($record) instanceof Product
                ? 'Save Product Listing'
                : 'Create Product Listing')
            ->schema(fn (Model $record, Schema $schema): Schema => ProductForm::configure(
                $schema->model(self::product($record) ?? Product::class),
                includeLinkedItem: false,
                costumeContext: $record instanceof Costume ? $record : null,
            ))
            ->mountUsing(function (Model $record, Schema $schema): void {
                self::authorizeManagement($record);

                $product = self::product($record);

                $schema
                    ->model($product ?? Product::class)
                    ->fill($product?->attributesToArray() ?? [
                        'name' => $record->getAttribute('name'),
                        'is_active' => true,
                        'include_productable_images' => false,
                        'send_purchase_notification' => false,
                    ]);
            })
            ->action(function (array $data, Model $record, Schema $schema): void {
                self::authorizeManagement($record);

                $product = self::product($record) ?? new Product;
                $data = ProductResource::normalizePricingData($data);

                DB::transaction(function () use ($data, $product, $record, $schema): void {
                    $product->fill($data);
                    $product->productable()->associate($record);
                    $product->save();

                    $schema->model($product)->saveRelationships();
                });

                $record->unsetRelation('product');

                Notification::make()
                    ->title($product->wasRecentlyCreated ? 'Product listing created' : 'Product listing updated')
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'manageProductListing';
    }

    private static function canManage(Model $record): bool
    {
        $product = self::product($record);

        return $product instanceof Product
            ? Gate::allows('update', $product)
            : Gate::allows('create', Product::class);
    }

    private static function authorizeManagement(Model $record): void
    {
        $product = self::product($record);

        $product instanceof Product
            ? Gate::authorize('update', $product)
            : Gate::authorize('create', Product::class);
    }

    private static function product(Model $record): ?Product
    {
        if (! $record instanceof Course && ! $record instanceof Costume) {
            throw new LogicException('Product listing actions only support courses and costumes.');
        }

        if ($record->relationLoaded('product')) {
            $product = $record->getRelation('product');

            return $product instanceof Product ? $product : null;
        }

        return Product::query()
            ->forProductable($record)
            ->first();
    }
}
