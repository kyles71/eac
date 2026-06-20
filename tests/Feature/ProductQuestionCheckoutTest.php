<?php

declare(strict_types=1);

use App\Actions\Store\CreateOrder;
use App\Filament\User\Pages\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('captures required and optional purchaser answers once per purchased unit', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create([
        'name' => 'Competition Shirt',
        'price' => 5000,
        'send_purchase_notification' => true,
    ]);
    $nameQuestion = ProductQuestion::factory()->for($product)->required()->create([
        'question' => 'Dancer name',
        'max_length' => 40,
        'sort_order' => 0,
    ]);
    $sizeQuestion = ProductQuestion::factory()->for($product)->required()->select(
        ['Small', 'Medium', 'Large'],
        allowsOther: true,
    )->create([
        'question' => 'Shirt size',
        'sort_order' => 1,
    ]);
    $noteQuestion = ProductQuestion::factory()->for($product)->create([
        'question' => 'Optional note',
        'sort_order' => 2,
    ]);
    $cartItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $order = app(CreateOrder::class)->handle(
        $user,
        questionAnswers: [
            $cartItem->id => [
                1 => [
                    "question_{$nameQuestion->id}" => 'Avery',
                    "question_{$sizeQuestion->id}" => 'Medium',
                ],
                2 => [
                    "question_{$nameQuestion->id}" => 'Jordan',
                    "question_{$sizeQuestion->id}" => 'Other',
                    "question_{$sizeQuestion->id}_other" => 'Youth XL',
                ],
            ],
        ],
    );

    $orderItem = $order->orderItems()->with('questionAnswers')->firstOrFail();

    expect($orderItem->purchase_notification_requested)->toBeTrue()
        ->and($orderItem->questionAnswers)->toHaveCount(6)
        ->and($orderItem->questionAnswers->where('unit_number', 1)->map->formattedAnswer()->values()->all())
        ->toBe(['Avery', 'Medium', 'Not answered'])
        ->and($orderItem->questionAnswers->where('unit_number', 2)->map->formattedAnswer()->values()->all())
        ->toBe(['Jordan', 'Youth XL', 'Not answered'])
        ->and($orderItem->questionAnswers->firstWhere('product_question_id', $noteQuestion->id)?->answer)
        ->toBeNull();
});

it('shows per-unit questions in the final checkout action modal', function (): void {
    $product = Product::factory()->standalone()->create(['name' => 'Team Jacket']);
    $question = ProductQuestion::factory()->for($product)->required()->create([
        'question' => 'Name for the jacket',
    ]);
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $component = livewire(Cart::class)
        ->mountAction('checkout')
        ->assertActionMounted('checkout');

    $schemaName = $component->instance()->getMountedActionSchemaName();
    $schema = $component->instance()->{$schemaName};
    $fields = collect($schema->getFlatFields(withHidden: true, withAbsoluteKeys: true))
        ->filter(fn ($field): bool => $field->getName() === "question_{$question->id}");
    $components = collect($schema->getFlatComponents(withHidden: true));
    $questionGrid = $components->first(fn ($component): bool => $component instanceof Grid);
    $questionSections = $components
        ->filter(fn ($component): bool => $component instanceof Section && str_starts_with((string) $component->getHeading(), 'Team Jacket'));

    expect($fields)->toHaveCount(2)
        ->and($questionGrid)->toBeInstanceOf(Grid::class)
        ->and($questionGrid->getColumns('default'))->toBe(1)
        ->and($questionGrid->getColumns('lg'))->toBe(2)
        ->and($questionSections)->toHaveCount(2)
        ->and($questionSections->every(fn (Section $section): bool => $section->getColumns('lg') === null))->toBeTrue();
});

it('shows an Other text input and only requires it for required questions', function (): void {
    $product = Product::factory()->standalone()->create();
    $requiredQuestion = ProductQuestion::factory()->for($product)->required()->select(['Small'], allowsOther: true)->create();
    $optionalQuestion = ProductQuestion::factory()->for($product)->select(['Blue'], allowsOther: true)->create();
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
    ]);
    $requiredOtherPath = "question_answers.{$cartItem->id}.1.question_{$requiredQuestion->id}_other";
    $optionalOtherPath = "question_answers.{$cartItem->id}.1.question_{$optionalQuestion->id}_other";

    $component = livewire(Cart::class)
        ->mountAction('checkout')
        ->assertSchemaComponentHidden($requiredOtherPath)
        ->assertSchemaComponentHidden($optionalOtherPath)
        ->fillForm([
            'question_answers' => [
                $cartItem->id => [
                    1 => [
                        "question_{$requiredQuestion->id}" => 'Other',
                        "question_{$optionalQuestion->id}" => 'Other',
                    ],
                ],
            ],
        ])
        ->assertSchemaComponentVisible($requiredOtherPath)
        ->assertSchemaComponentVisible($optionalOtherPath);

    $schemaName = $component->instance()->getMountedActionSchemaName();
    $fields = collect($component->instance()->{$schemaName}->getFlatFields(withHidden: true, withAbsoluteKeys: true));
    $requiredOtherField = $fields->first(fn ($field): bool => $field->getName() === "question_{$requiredQuestion->id}_other");
    $optionalOtherField = $fields->first(fn ($field): bool => $field->getName() === "question_{$optionalQuestion->id}_other");

    expect($requiredOtherField->isRequired())->toBeTrue()
        ->and($optionalOtherField->isRequired())->toBeFalse();
});

it('rejects missing required purchaser answers in the order action', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create(['name' => 'Team Jacket']);
    ProductQuestion::factory()->for($product)->required()->create([
        'question' => 'Name for the jacket',
    ]);
    CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    app(CreateOrder::class)->handle($user);
})->throws(InvalidArgumentException::class, 'Please answer "Name for the jacket" for Team Jacket.');

it('rejects text beyond the configured maximum length', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create();
    $question = ProductQuestion::factory()->for($product)->create([
        'question' => 'Short answer',
        'max_length' => 5,
    ]);
    $cartItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    app(CreateOrder::class)->handle($user, questionAnswers: [
        $cartItem->id => [1 => ["question_{$question->id}" => 'Too long']],
    ]);
})->throws(InvalidArgumentException::class, 'may not be longer than 5 characters');

it('rejects unavailable select choices', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create();
    $question = ProductQuestion::factory()->for($product)->select(['Small'], allowsOther: true)->create([
        'question' => 'Size',
    ]);
    $cartItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    app(CreateOrder::class)->handle($user, questionAnswers: [
        $cartItem->id => [
            1 => [
                "question_{$question->id}" => 'Large',
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'selected answer');

it('requires an Other answer only when the select question is required', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create();
    $question = ProductQuestion::factory()->for($product)->required()->select(['Small'], allowsOther: true)->create([
        'question' => 'Size',
    ]);
    $cartItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    app(CreateOrder::class)->handle($user, questionAnswers: [
        $cartItem->id => [1 => ["question_{$question->id}" => 'Other']],
    ]);
})->throws(InvalidArgumentException::class, 'Please specify the Other answer');

it('saves an optional Other answer as its custom value or Other when left blank', function (?string $otherAnswer, string $savedAnswer): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create();
    $question = ProductQuestion::factory()->for($product)->select(['Small'], allowsOther: true)->create([
        'question' => 'Size',
    ]);
    $cartItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $order = app(CreateOrder::class)->handle($user, questionAnswers: [
        $cartItem->id => [
            1 => [
                "question_{$question->id}" => 'Other',
                "question_{$question->id}_other" => $otherAnswer,
            ],
        ],
    ]);

    $answer = $order->orderItems()->firstOrFail()->questionAnswers()->firstOrFail();

    expect($answer->answer)->toBe($savedAnswer)
        ->and($answer->formattedAnswer())->toBe($savedAnswer);
})->with([
    'custom answer' => ['Youth XL', 'Youth XL'],
    'blank answer' => [null, 'Other'],
]);
