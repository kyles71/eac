<?php

declare(strict_types=1);

use App\Models\GiftCardType;
use App\Models\Product;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->withVite();

    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->create();

    $this->product = Product::factory()
        ->forGiftCardType($giftCardType)
        ->create();

    foreach ([
        ['first-gallery.jpg', 800, 600],
        ['second-gallery.jpg', 900, 600],
        ['third-gallery.jpg', 600, 900],
    ] as [$fileName, $width, $height]) {
        $this->product
            ->addMedia(UploadedFile::fake()->image($fileName, $width, $height))
            ->toMediaCollection('images');
    }
});

afterEach(function () {
    $this->product?->clearMediaCollection('images');
});

it('opens, zooms, and navigates the product gallery lightbox', function () {
    $page = visit("/dancefam/store/products/{$this->product->id}")
        ->assertVisible('[data-product-gallery-item]:first-child')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($page->script("customElements.get('eac-product-gallery') !== undefined"))->toBeTrue();
    expect($page->script("document.querySelector('eac-product-gallery').dataset.productGalleryReady"))->toBe('true');

    $page
        ->click('[data-product-gallery-item]:nth-child(2)')
        ->assertVisible('.pswp')
        ->assertSee('2 of 3');

    expect($page->script(
        "document.querySelector('[data-product-gallery-thumbnail=\"1\"]').getAttribute('aria-current')",
    ))->toBe('true');

    $page
        ->click('.pswp__button--zoom')
        ->wait(0.5);

    expect($page->script("document.querySelector('.pswp').classList.contains('pswp--zoomed-in')"))
        ->toBeTrue();

    $page
        ->click('.pswp__button--zoom')
        ->wait(0.5);

    expect($page->script("document.querySelector('.pswp').classList.contains('pswp--zoomed-in')"))
        ->toBeFalse();

    $page
        ->click('.pswp__button--arrow--next')
        ->assertSee('3 of 3');

    expect($page->script("document.querySelector('.pswp__button--arrow--next').disabled"))
        ->toBeTrue();

    $page
        ->keys('.pswp', 'ArrowLeft')
        ->assertSee('2 of 3')
        ->click('[data-product-gallery-thumbnail="0"]')
        ->assertSee('1 of 3');

    expect($page->script("document.querySelector('.pswp__button--arrow--prev').disabled"))
        ->toBeTrue();

    $page
        ->keys('.pswp', 'Escape')
        ->assertNotPresent('.pswp')
        ->wait(0.1)
        ->assertNoJavaScriptErrors();

    expect($page->script(
        "document.activeElement === document.querySelector('[data-product-gallery-item]:nth-child(2)')",
    ))->toBeTrue();
});
