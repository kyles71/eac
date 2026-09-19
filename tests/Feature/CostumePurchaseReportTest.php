<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Costumes\CostumeResource;
use App\Filament\Admin\Resources\Costumes\Pages\ListCostumes;
use App\Filament\Admin\Resources\Costumes\Pages\ProductsNotOrdered;
use App\Models\Costume;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use App\Services\CostumePurchaseReportService;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('includes costume vendor number and color in purchase reports', function (): void {
    $costume = Costume::factory()->create([
        'name' => 'Royal Ballet Costume',
        'vendor' => 'Curtain Call',
        'vendor_number' => 'CC-204',
        'costume_color' => 'Royal Blue with Silver',
    ]);
    $product = Product::factory()->forCostume($costume)->create(['name' => 'Royal Ballet Listing']);
    $purchaser = User::factory()->create([
        'first_name' => 'Avery',
        'last_name' => 'Dancer',
        'email' => 'avery@example.com',
    ]);
    OrderItem::factory()
        ->for(Order::factory()->completed()->for($purchaser))
        ->for($product)
        ->create(['quantity' => 1]);

    $rows = costumeReportRows(app(CostumePurchaseReportService::class)->downloadPurchasesForCostume($costume));
    $purchase = array_combine($rows[0], $rows[1]);

    expect($rows[0])->toContain('Vendor', 'Vendor Number', 'Costume Color')
        ->and($purchase)->toMatchArray([
            'Costume' => 'Royal Ballet Costume',
            'Vendor' => 'Curtain Call',
            'Vendor Number' => 'CC-204',
            'Costume Color' => 'Royal Blue with Silver',
            'Product Listing' => 'Royal Ballet Listing',
        ]);
});

it('combines only active costume requirements with a Not Ordered status', function (): void {
    $course = Course::factory()->create(['name' => 'Tuesday Ballet']);
    $notOrderedHousehold = User::factory()->create([
        'first_name' => 'Not Ordered',
        'last_name' => 'Household',
        'email' => 'not-ordered@example.com',
    ]);
    $orderedHousehold = User::factory()->create([
        'first_name' => 'Fulfilled',
        'last_name' => 'Family',
    ]);
    $partialHousehold = User::factory()->create([
        'first_name' => 'Partially Purchased',
        'last_name' => 'Family',
    ]);
    $notOrderedStudent = Student::factory()->for($notOrderedHousehold)->create(['first_name' => 'Nora']);
    $orderedStudent = Student::factory()->for($orderedHousehold)->create();
    $firstPartialStudent = Student::factory()->for($partialHousehold)->create();
    $secondPartialStudent = Student::factory()->for($partialHousehold)->create();

    Enrollment::factory()->for($course)->for($notOrderedHousehold)->withStudent($notOrderedStudent)->create();
    Enrollment::factory()->for($course)->for($orderedHousehold)->withStudent($orderedStudent)->create();
    Enrollment::factory()->for($course)->for($partialHousehold)->withStudent($firstPartialStudent)->create();
    Enrollment::factory()->for($course)->for($partialHousehold)->withStudent($secondPartialStudent)->create();

    $costume = Costume::factory()->for($course)->create([
        'name' => 'Blue Ballet Costume',
        'vendor' => 'Costume Source',
        'vendor_number' => 'CS-12',
        'costume_color' => 'Sky Blue',
    ]);
    $product = Product::factory()->forCostume($costume)->purchaseRequired()->create([
        'name' => 'Blue Ballet Costume Listing',
        'purchase_reminder_on' => now()->toDateString(),
    ]);

    OrderItem::factory()
        ->for(Order::factory()->completed()->for($orderedHousehold))
        ->for($product)
        ->create(['quantity' => 1]);
    OrderItem::factory()
        ->for(Order::factory()->completed()->for($partialHousehold))
        ->for($product)
        ->create(['quantity' => 1]);

    $inactiveCourse = Course::factory()->create();
    $inactiveHousehold = User::factory()->create();
    Enrollment::factory()->for($inactiveCourse)->for($inactiveHousehold)->create();
    Product::factory()
        ->forCostume(Costume::factory()->for($inactiveCourse)->create(['name' => 'Inactive Costume']))
        ->purchaseRequired()
        ->inactive()
        ->create();

    $rows = app(CostumePurchaseReportService::class)->notOrderedRows();

    expect($rows->all())->toHaveCount(1)
        ->and($rows->first())->toMatchArray([
            'costume' => 'Blue Ballet Costume',
            'vendor' => 'Costume Source',
            'vendor_number' => 'CS-12',
            'costume_color' => 'Sky Blue',
            'course' => 'Tuesday Ballet',
            'product' => 'Blue Ballet Costume Listing',
            'household' => 'Not Ordered Household',
            'household_email' => 'not-ordered@example.com',
            'required' => 1,
            'status' => 'Not Ordered',
        ]);

    $csvRows = costumeReportRows(app(CostumePurchaseReportService::class)->downloadNotOrdered());
    $notOrdered = array_combine($csvRows[0], $csvRows[1]);

    expect($notOrdered)->toMatchArray([
        'Costume' => 'Blue Ballet Costume',
        'Vendor' => 'Costume Source',
        'Vendor Number' => 'CS-12',
        'Costume Color' => 'Sky Blue',
        'Household Name' => 'Not Ordered Household',
        'Status' => 'Not Ordered',
    ]);

    livewire(ProductsNotOrdered::class)
        ->loadTable()
        ->assertSee('Blue Ballet Costume')
        ->assertSee('Not Ordered Household')
        ->assertDontSee('Fulfilled Family')
        ->assertDontSee('Partially Purchased Family')
        ->searchTable('Nora')
        ->assertSee('Not Ordered Household');

    livewire(ListCostumes::class)
        ->assertActionVisible('viewProductsNotOrdered')
        ->assertActionVisible('downloadProductsNotOrdered');

    expect(CostumeResource::getUrl('products-not-ordered'))
        ->toContain('/admin/costumes/products-not-ordered');
});

/** @return list<list<string>> */
function costumeReportRows(StreamedResponse $response): array
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
