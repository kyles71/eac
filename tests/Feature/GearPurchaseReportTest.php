<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\ProductQuestionType;
use App\Models\Gear;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use App\Models\User;
use App\Services\GearPurchaseReportService;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('exports one row per completed purchased unit using historical snapshots', function () {
    $purchaser = User::factory()->create([
        'first_name' => 'Natalie',
        'last_name' => 'Dancer',
        'email' => 'natalie@example.com',
    ]);
    $gear = Gear::factory()->create(['name' => 'Competition Jacket']);
    $product = Product::factory()->forGear($gear)->create(['name' => 'Fall Jacket Listing']);
    $sizeQuestion = ProductQuestion::factory()->for($product)->create([
        'question' => 'Size',
        'type' => ProductQuestionType::Select,
        'sort_order' => 0,
    ]);
    $nameQuestion = ProductQuestion::factory()->for($product)->create([
        'question' => 'Customization Name',
        'type' => ProductQuestionType::Text,
        'sort_order' => 1,
    ]);
    $order = Order::factory()->completed()->for($purchaser)->create([
        'created_at' => CarbonImmutable::parse('2026-08-19 18:30:00', 'UTC'),
    ]);
    $orderItem = OrderItem::factory()->for($order)->for($product)->create([
        'quantity' => 2,
        'unit_price' => 5500,
        'total_price' => 11000,
    ]);

    foreach ([1 => ['Medium', 'Natalie'], 2 => ['Large', 'Nat']] as $unitNumber => [$size, $name]) {
        ProductQuestionAnswer::factory()->for($orderItem)->for($sizeQuestion)->create([
            'unit_number' => $unitNumber,
            'question' => 'Size',
            'question_type' => ProductQuestionType::Select,
            'question_order' => 0,
            'selected_option' => $size,
            'answer' => null,
        ]);
        ProductQuestionAnswer::factory()->for($orderItem)->for($nameQuestion)->create([
            'unit_number' => $unitNumber,
            'question' => 'Customization Name',
            'question_type' => ProductQuestionType::Text,
            'question_order' => 1,
            'selected_option' => null,
            'answer' => $name,
        ]);
    }

    $product->update(['name' => 'Renamed Jacket Listing']);
    $sizeQuestion->update(['question' => 'Current Size Label']);
    $nameQuestion->delete();

    $rows = gearPurchaseReportRows(app(GearPurchaseReportService::class)->downloadForProduct($product));

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe([
            'Order Number',
            'Purchase Date',
            'Purchaser Name',
            'Purchaser Email',
            'Gear',
            'Product Listing',
            'Unit Number',
            'Quantity',
            'Original Line Quantity',
            'Unit Price',
            'Size',
            'Customization Name',
        ]);

    $firstUnit = array_combine($rows[0], $rows[1]);
    $secondUnit = array_combine($rows[0], $rows[2]);

    expect($firstUnit)->toMatchArray([
        'Order Number' => (string) $order->id,
        'Purchase Date' => '2026-08-19 14:30:00',
        'Purchaser Name' => 'Natalie Dancer',
        'Purchaser Email' => 'natalie@example.com',
        'Gear' => 'Competition Jacket',
        'Product Listing' => 'Fall Jacket Listing',
        'Unit Number' => '1',
        'Quantity' => '1',
        'Original Line Quantity' => '2',
        'Unit Price' => '55.00',
        'Size' => 'Medium',
        'Customization Name' => 'Natalie',
    ])->and($secondUnit)->toMatchArray([
        'Unit Number' => '2',
        'Size' => 'Large',
        'Customization Name' => 'Nat',
    ])->and($orderItem->refresh()->product_name)->toBe('Fall Jacket Listing')
        ->and(ProductQuestionAnswer::query()
            ->where('order_item_id', $orderItem->id)
            ->where('question', 'Customization Name')
            ->whereNull('product_question_id')
            ->count())->toBe(2);
});

it('scopes reports by Gear and Product and excludes non-completed Orders', function () {
    $firstGear = Gear::factory()->create();
    $firstProduct = Product::factory()->forGear($firstGear)->create();
    $secondProduct = Product::factory()->forGear($firstGear)->create();
    $otherGear = Gear::factory()->create();
    $otherProduct = Product::factory()->forGear($otherGear)->create();

    gearReportOrderItem($firstProduct, OrderStatus::Completed);
    gearReportOrderItem($secondProduct, OrderStatus::Completed);
    gearReportOrderItem($otherProduct, OrderStatus::Completed);

    foreach ([
        OrderStatus::Pending,
        OrderStatus::Processing,
        OrderStatus::Failed,
        OrderStatus::Refunded,
        OrderStatus::Cancelled,
    ] as $status) {
        gearReportOrderItem($firstProduct, $status);
    }

    $report = app(GearPurchaseReportService::class);

    expect(gearPurchaseReportRows($report->downloadAll()))->toHaveCount(4)
        ->and(gearPurchaseReportRows($report->downloadForGear($firstGear)))->toHaveCount(3)
        ->and(gearPurchaseReportRows($report->downloadForProduct($firstProduct)))->toHaveCount(2);
});

it('combines differing question sets across Product listings', function () {
    $gear = Gear::factory()->create();
    $sizedProduct = Product::factory()->forGear($gear)->create(['name' => 'Sized Listing']);
    $customizedProduct = Product::factory()->forGear($gear)->create(['name' => 'Customized Listing']);
    $sizedOrderItem = gearReportOrderItem($sizedProduct, OrderStatus::Completed);
    $customizedOrderItem = gearReportOrderItem($customizedProduct, OrderStatus::Completed);

    ProductQuestionAnswer::factory()->for($sizedOrderItem)->create([
        'product_question_id' => null,
        'unit_number' => 1,
        'question' => 'Size',
        'selected_option' => 'Medium',
        'answer' => null,
    ]);
    ProductQuestionAnswer::factory()->for($customizedOrderItem)->create([
        'product_question_id' => null,
        'unit_number' => 1,
        'question' => 'Customization Name',
        'selected_option' => null,
        'answer' => 'Natalie',
    ]);

    $rows = gearPurchaseReportRows(app(GearPurchaseReportService::class)->downloadForGear($gear));
    $rowsByProduct = collect(array_slice($rows, 1))
        ->mapWithKeys(fn (array $row): array => [
            $row[5] => array_combine($rows[0], $row),
        ]);

    expect($rows[0])->toContain('Size', 'Customization Name')
        ->and($rowsByProduct['Sized Listing'])->toMatchArray([
            'Size' => 'Medium',
            'Customization Name' => '',
        ])->and($rowsByProduct['Customized Listing'])->toMatchArray([
            'Size' => '',
            'Customization Name' => 'Natalie',
        ]);
});

it('protects spreadsheet cells from formula execution', function () {
    $purchaser = User::factory()->create([
        'first_name' => '=DANGEROUS',
        'last_name' => 'Name',
    ]);
    $gear = Gear::factory()->create(['name' => '+DANGEROUS']);
    $product = Product::factory()->forGear($gear)->create(['name' => '@DANGEROUS']);
    $order = Order::factory()->completed()->for($purchaser)->create();
    $orderItem = OrderItem::factory()->for($order)->for($product)->create([
        'quantity' => 1,
    ]);
    ProductQuestionAnswer::factory()->for($orderItem)->create([
        'product_question_id' => null,
        'unit_number' => 1,
        'question' => '-Dangerous Question',
        'answer' => '=Dangerous Answer',
    ]);

    $rows = gearPurchaseReportRows(app(GearPurchaseReportService::class)->downloadForGear($gear));
    $row = array_combine($rows[0], $rows[1]);

    expect($rows[0])->toContain("'-Dangerous Question")
        ->and($row['Purchaser Name'])->toBe("'=DANGEROUS Name")
        ->and($row['Gear'])->toBe("'+DANGEROUS")
        ->and($row['Product Listing'])->toBe("'@DANGEROUS")
        ->and($row["'-Dangerous Question"])->toBe("'=Dangerous Answer");
});

function gearReportOrderItem(Product $product, OrderStatus $status): OrderItem
{
    $order = Order::factory()->create(['status' => $status]);

    return OrderItem::factory()->for($order)->for($product)->create([
        'quantity' => 1,
    ]);
}

/** @return list<list<string>> */
function gearPurchaseReportRows(StreamedResponse $response): array
{
    ob_start();

    try {
        ($response->getCallback())();
        $content = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    $content = str_starts_with($content, "\xEF\xBB\xBF")
        ? mb_substr($content, 1)
        : $content;

    return collect(preg_split('/\r\n|\n|\r/', mb_trim($content)) ?: [])
        ->map(fn (string $line): array => str_getcsv($line, ',', '"', ''))
        ->all();
}
