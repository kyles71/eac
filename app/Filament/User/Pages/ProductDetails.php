<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\AddToCart;
use App\Contracts\HasCapacity;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductDetails extends Page
{
    public ?Product $product = null;

    protected static ?string $title = 'Product Details';

    protected static ?string $slug = 'store/products/{product}';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.user.pages.product-details';

    public function mount(Product $product): void
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $product->loadMissing(['media', 'productable', 'requiresCourse']);

        if ($product->productable instanceof HasMedia) {
            $product->productable->loadMissing('media');
        }

        if (! $product->is_active || $product->price <= 0 || ! $product->canBePurchasedBy($user)) {
            Notification::make()
                ->title('Product unavailable')
                ->body('That product is not available for purchase.')
                ->danger()
                ->send();

            $this->redirect(Store::getUrl());

            return;
        }

        $this->product = $product;
        $this->heading = $product->name;
        $this->subheading = 'Review product details and add this item to your cart.';
    }

    public function getTitle(): string|Htmlable
    {
        return $this->product?->name ?? self::$title ?? 'Product Details';
    }

    public function content(Schema $schema): Schema
    {
        if ($this->product === null) {
            return $schema->components([]);
        }

        return $schema
            ->components([
                Grid::make()
                    ->columns([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->schema([
                        Section::make('Gallery')
                            ->schema($this->getGallerySchema())
                            ->columnSpan(1),
                        Section::make('Product Details')
                            ->schema($this->getDetailsSchema())
                            ->columnSpan(1),
                    ]),
            ]);
    }

    public function addToCartAction(): Action
    {
        return Action::make('addToCart')
            ->label('Add to Cart')
            ->icon(Heroicon::OutlinedShoppingCart)
            ->color('primary')
            ->disabled(fn (): bool => $this->product === null || $this->isSoldOut())
            ->action(function (): void {
                if ($this->product === null) {
                    return;
                }

                try {
                    /** @var \App\Models\User $user */
                    $user = auth()->user();

                    $addToCart = new AddToCart;
                    $addToCart->handle($user, $this->product);

                    $this->dispatch('refresh-sidebar');

                    Notification::make()
                        ->title('Added to cart')
                        ->body("\"{$this->product->name}\" has been added to your cart.")
                        ->success()
                        ->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Could not add to cart')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getGallerySchema(): array
    {
        $images = $this->product?->galleryImages() ?? collect();

        if ($images->isEmpty()) {
            return [
                TextEntry::make('empty_gallery')
                    ->hiddenLabel()
                    ->state('No product images are available.'),
            ];
        }

        return [
            Grid::make()
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                ])
                ->schema(
                    $images
                        ->map(fn (Media $media): Image => Image::make(
                            $media->getUrl(),
                            $media->name,
                        )
                            ->imageHeight('16rem')
                            ->imageWidth('100%')
                            ->extraAttributes([
                                'class' => 'rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10',
                            ]))
                        ->all()
                ),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component|Action>
     */
    private function getDetailsSchema(): array
    {
        $details = [
            TextEntry::make('price')
                ->label('Price')
                ->state(fn (): string => $this->product?->formattedPrice() ?? ''),
            TextEntry::make('description')
                ->label('Description')
                ->state(fn (): ?string => $this->product?->description)
                ->placeholder('No description available.')
                ->columnSpanFull(),
        ];

        foreach ($this->product?->storefrontDetails() ?? [] as $label => $value) {
            $details[] = TextEntry::make('storefront_detail_'.count($details))
                ->label($label)
                ->state($value);
        }

        if ($this->product?->requiresCourse !== null) {
            $details[] = TextEntry::make('requires_course')
                ->label('Requires Enrollment In')
                ->state($this->product->requiresCourse->name);
        }

        $details[] = Actions::make([
            $this->addToCartAction,
        ])
            ->fullWidth()
            ->columnSpanFull();

        return $details;
    }

    private function isSoldOut(): bool
    {
        return $this->product?->productable instanceof HasCapacity
            && $this->product->productable->getAvailableCapacity() <= 0;
    }
}
