<?php

declare(strict_types=1);

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductQuestion;

beforeEach(function (): void {
    $this->withVite();
});

it('collects and edits add-time purchaser answers from the store and cart', function (): void {
    $product = Product::factory()->standalone()->create([
        'name' => 'Competition Jacket',
        'price' => 5000,
    ]);
    $question = ProductQuestion::factory()->for($product)->required()->create([
        'question' => 'Dancer name',
    ]);

    visit('/dancefam/store')
        ->assertSee('Competition Jacket')
        ->click('Add to Cart')
        ->assertSee('Dancer name')
        ->fill("mountedActionSchema0.question_answers.1.question_{$question->id}", 'Avery')
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->assertSee('Added to cart')
        ->assertNoJavaScriptErrors();

    visit('/dancefam/cart')
        ->assertSee('Competition Jacket')
        ->assertSee('Item 1')
        ->assertSee('Dancer name')
        ->assertSee('Avery')
        ->click('Edit Details')
        ->assertSee('Dancer name')
        ->fill("mountedActionSchema0.question_answers.1.question_{$question->id}", 'Taylor')
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->assertSee('Purchaser answers updated')
        ->assertNoJavaScriptErrors();

    $cartItem = CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $product->id)
        ->firstOrFail();

    expect($cartItem->storedQuestionAnswers())->toBe([
        1 => ["question_{$question->id}" => 'Taylor'],
    ]);
});

it('collects add-time purchaser answers from product details', function (): void {
    $product = Product::factory()->standalone()->create([
        'name' => 'Competition Shirt',
        'price' => 3500,
    ]);
    $question = ProductQuestion::factory()->for($product)->required()->create([
        'question' => 'Shirt name',
    ]);

    visit("/dancefam/store/products/{$product->id}")
        ->assertSee('Competition Shirt')
        ->click('Add to Cart')
        ->assertSee('Shirt name')
        ->fill("mountedActionSchema0.question_answers.1.question_{$question->id}", 'Jordan')
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->assertSee('Added to cart')
        ->assertNoJavaScriptErrors();

    $cartItem = CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $product->id)
        ->firstOrFail();

    expect($cartItem->storedQuestionAnswers())->toBe([
        1 => ["question_{$question->id}" => 'Jordan'],
    ]);
});
