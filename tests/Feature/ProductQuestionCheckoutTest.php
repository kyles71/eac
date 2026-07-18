<?php

declare(strict_types=1);

use App\Actions\Store\CreateOrder;
use App\Filament\User\Pages\Cart;
use App\Models\CartItem;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

it('keeps purchaser answers attached to separate custom gift card amount lines', function (): void {
    $user = User::factory()->create();
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $product = Product::factory()->forGiftCardType($giftCardType)->create([
        'name' => 'Gift Card',
    ]);
    $question = ProductQuestion::factory()->for($product)->required()->create([
        'question' => 'Recipient name',
    ]);
    $smallGiftCard = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'custom_gift_card_amount' => 2500,
    ]);
    $largeGiftCard = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'custom_gift_card_amount' => 7500,
    ]);

    $order = app(CreateOrder::class)->handle(
        $user,
        questionAnswers: [
            $smallGiftCard->id => [
                1 => ["question_{$question->id}" => 'Avery'],
            ],
            $largeGiftCard->id => [
                1 => ["question_{$question->id}" => 'Jordan'],
            ],
        ],
    );

    $orderItems = $order->orderItems()
        ->with('questionAnswers')
        ->orderBy('unit_price')
        ->get();

    expect($orderItems)->toHaveCount(2)
        ->and($orderItems[0]->unit_price)->toBe(2500)
        ->and($orderItems[0]->questionAnswers->first()?->answer)->toBe('Avery')
        ->and($orderItems[1]->unit_price)->toBe(7500)
        ->and($orderItems[1]->questionAnswers->first()?->answer)->toBe('Jordan');
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

it('omits add-time questions from checkout and saves both answer sources in a mixed cart', function (): void {
    $addTimeProduct = Product::factory()->standalone()->create([
        'name' => 'Team Jacket',
        'ask_purchaser_questions_when_adding_to_cart' => true,
    ]);
    $addTimeQuestion = ProductQuestion::factory()->for($addTimeProduct)->required()->create([
        'question' => 'Jacket name',
    ]);
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $addTimeProduct->id,
        'question_answers' => [
            1 => ["question_{$addTimeQuestion->id}" => 'Avery'],
        ],
    ]);

    $checkoutProduct = Product::factory()->standalone()->create(['name' => 'Team Shirt']);
    $checkoutQuestion = ProductQuestion::factory()->for($checkoutProduct)->required()->create([
        'question' => 'Shirt name',
    ]);
    $checkoutCartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $checkoutProduct->id,
    ]);

    $component = livewire(Cart::class)
        ->mountAction('checkout')
        ->assertActionMounted('checkout');
    $schemaName = $component->instance()->getMountedActionSchemaName();
    $fields = collect($component->instance()->{$schemaName}->getFlatFields(withHidden: true, withAbsoluteKeys: true));

    expect($fields->contains(fn ($field): bool => $field->getName() === "question_{$addTimeQuestion->id}"))->toBeFalse()
        ->and($fields->contains(fn ($field): bool => $field->getName() === "question_{$checkoutQuestion->id}"))->toBeTrue();

    $component
        ->fillForm([
            'question_answers' => [
                $checkoutCartItem->id => [
                    1 => ["question_{$checkoutQuestion->id}" => 'Jordan'],
                ],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $order = auth()->user()->orders()->latest()->firstOrFail();
    $answersByProduct = $order->orderItems()
        ->with('questionAnswers')
        ->get()
        ->mapWithKeys(fn ($orderItem): array => [
            $orderItem->product_id => $orderItem->questionAnswers->first()?->formattedAnswer(),
        ]);

    expect($answersByProduct->get($addTimeProduct->id))->toBe('Avery')
        ->and($answersByProduct->get($checkoutProduct->id))->toBe('Jordan');
});

it('shows an Other text input and only requires it for required questions', function (): void {
    $product = Product::factory()->standalone()->create();
    $requiredQuestion = ProductQuestion::factory()->for($product)->required()->select(['Small'], allowsOther: true)->create();
    $optionalQuestion = ProductQuestion::factory()->for($product)->select(['Blue'], allowsOther: true)->create();
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
    ]);
    $component = livewire(Cart::class)
        ->mountAction('checkout')
        ->fillForm([
            'question_answers' => [
                $cartItem->id => [
                    1 => [
                        "question_{$requiredQuestion->id}" => 'Other',
                        "question_{$optionalQuestion->id}" => 'Other',
                    ],
                ],
            ],
        ]);

    $schemaName = $component->instance()->getMountedActionSchemaName();
    $fields = collect($component->instance()->{$schemaName}->getFlatFields(withHidden: true, withAbsoluteKeys: true));
    $requiredSelectField = $fields->first(fn ($field): bool => $field->getName() === "question_{$requiredQuestion->id}");
    $optionalSelectField = $fields->first(fn ($field): bool => $field->getName() === "question_{$optionalQuestion->id}");
    $requiredOtherField = $fields->first(fn ($field): bool => $field->getName() === "question_{$requiredQuestion->id}_other");
    $optionalOtherField = $fields->first(fn ($field): bool => $field->getName() === "question_{$optionalQuestion->id}_other");

    expect($requiredSelectField)->toBeInstanceOf(Select::class)
        ->and($optionalSelectField)->toBeInstanceOf(Select::class)
        ->and($requiredOtherField)->toBeInstanceOf(TextInput::class)
        ->and($optionalOtherField)->toBeInstanceOf(TextInput::class)
        ->and($requiredSelectField->isLive())->toBeFalse()
        ->and($optionalSelectField->isLive())->toBeFalse()
        ->and(implode("\n", $requiredSelectField->getAfterStateUpdatedJs()))
        ->toContain("\$set('question_{$requiredQuestion->id}_other', null)")
        ->and(implode("\n", $optionalSelectField->getAfterStateUpdatedJs()))
        ->toContain("\$set('question_{$optionalQuestion->id}_other', null)")
        ->and(mb_trim($requiredOtherField->getVisibleJs() ?? ''))
        ->toBe("\$get('question_{$requiredQuestion->id}') === 'Other'")
        ->and(mb_trim($optionalOtherField->getVisibleJs() ?? ''))
        ->toBe("\$get('question_{$optionalQuestion->id}') === 'Other'")
        ->and($requiredOtherField->isRequired())->toBeTrue()
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

it('revalidates stored add-time answers when question choices change', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create([
        'ask_purchaser_questions_when_adding_to_cart' => true,
    ]);
    $question = ProductQuestion::factory()->for($product)->required()->select(['Small', 'Large'])->create([
        'question' => 'Size',
    ]);
    $cartItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'question_answers' => [
            1 => ["question_{$question->id}" => 'Large'],
        ],
    ]);
    $question->update(['options' => ['Small']]);

    app(CreateOrder::class)->handle($user);
})->throws(InvalidArgumentException::class, 'selected answer');

it('ignores answers for purchaser questions that were deleted before checkout', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create([
        'ask_purchaser_questions_when_adding_to_cart' => true,
    ]);
    $question = ProductQuestion::factory()->for($product)->required()->create();
    CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'question_answers' => [
            1 => ["question_{$question->id}" => 'Avery'],
        ],
    ]);
    $question->delete();

    $order = app(CreateOrder::class)->handle($user);

    expect($order->orderItems()->firstOrFail()->questionAnswers()->count())->toBe(0);
});

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
