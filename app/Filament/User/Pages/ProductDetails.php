<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\AddToCart;
use App\Contracts\HasCapacity;
use App\Models\Course;
use App\Models\Product;
use App\Support\Filament\CourseStaffPresenter;
use App\Support\Filament\CustomGiftCardAmountField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;

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
        $product->loadMorph('productable', [
            Course::class => ['events', 'teachers.media'],
        ]);

        if ($product->productable instanceof HasMedia) {
            $product->productable->loadMissing('media');
        }

        if (! $product->canBePurchasedBy($user)) {
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

    public function getTitle(): string
    {
        return $this->product !== null ? $this->product->name : self::$title ?? 'Product Details';
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
            ->modalHidden(fn (): bool => $this->product?->requiresAddToCartInformation() !== true)
            ->modalHeading(fn (): string => $this->product?->allowsCustomGiftCardAmount() === true
                ? 'Choose Gift Card Amount'
                : 'Add to Cart')
            ->modalSubmitActionLabel('Add to Cart')
            ->fillForm(fn (): array => [
                'custom_gift_card_amount' => $this->product?->suggestedCustomGiftCardAmount(),
            ])
            ->schema(fn (): array => $this->product === null
                ? []
                : CustomGiftCardAmountField::schema($this->product))
            ->disabled(fn (): bool => $this->product === null || $this->isSoldOut())
            ->action(function (array $data): void {
                if ($this->product === null) {
                    return;
                }

                try {
                    /** @var \App\Models\User $user */
                    $user = auth()->user();

                    $addToCart = new AddToCart;
                    $addToCart->handle(
                        $user,
                        $this->product,
                        customGiftCardAmount: CustomGiftCardAmountField::amountFromActionData($this->product, $data),
                    );

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCart')
                ->label('Back to Store')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Store::getUrl()),
        ];
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
            View::make('filament.user.pages.product-gallery')
                ->viewData([
                    'images' => $images,
                ]),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component|Action>
     */
    private function getDetailsSchema(): array
    {
        if ($this->product === null) {
            return [];
        }

        $product = $this->product;

        $details = [
            TextEntry::make('price')
                ->label('Price')
                ->state(fn (): string => $product->storefrontPriceLabel()),
            TextEntry::make('description')
                ->label('Description')
                ->state(fn (): ?string => $product->description)
                ->placeholder('No description available.')
                ->columnSpanFull(),
        ];

        foreach ($product->storefrontDetails() as $label => $value) {
            $details[] = TextEntry::make('storefront_detail_'.count($details))
                ->label($label)
                ->state(
                    $label === 'Teacher' && $product->productable instanceof Course
                        ? CourseStaffPresenter::render($product->productable)
                        : $value
                );
        }

        if ($product->requiresCourse !== null) {
            $details[] = TextEntry::make('requires_course')
                ->label('Requires Enrollment In')
                ->state($product->requiresCourse->name);
        }

        $details[] = Actions::make([
            $this->addToCartAction(),
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
