<?php

declare(strict_types=1);

use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\CartItem;
use App\Models\CreditGrant;
use App\Models\Gear;
use App\Models\GiftCardType;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Support\MediaDisks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Kyle\FilamentMailManager\Enums\LayoutMode;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    suspendCurrentCostumeFeatureForLegacyMigrationTest();
});

afterEach(function (): void {
    if (Schema::hasTable('costumes') && ! Schema::hasTable('gear')) {
        $legacyMigration = require database_path('migrations/2026_08_17_180444_rename_costumes_to_gear.php');
        $legacyMigration->up();
    }

    restoreCurrentCostumeFeatureAfterLegacyMigrationTest();
});

it('renames legacy costume data to gear without changing linked record ids', function (): void {
    Storage::fake(MediaDisks::public());

    $gear = Gear::factory()->create(['name' => 'Competition Jacket']);
    $product = Product::factory()->forGear($gear)->create([
        'is_active' => true,
        'available_until' => now()->subMinute(),
    ]);
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
    ]);
    $media = $gear->addMedia(UploadedFile::fake()->image('jacket.jpg'))
        ->toMediaCollection('images');
    $order = Order::factory()->create([
        'status' => OrderStatus::Completed,
        'user_id' => auth()->id(),
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    $installment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'status' => InstallmentStatus::Pending,
    ]);
    $paymentPlanTemplate = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Gear,
    ]);
    $giftCardType = GiftCardType::factory()->create([
        'restricted_to_product_type' => ProductType::Gear,
    ]);
    $creditGrant = CreditGrant::factory()->create([
        'restricted_to_product_type' => ProductType::Gear,
    ]);
    $template = app(ManagedTemplateRepository::class)->saveOverride('order-receipt', [
        'layout_mode' => LayoutMode::None,
        'body' => '<p>Receipt</p>{{ conditional.gear }}',
        'conditional_sections' => [
            'gear' => '<p>Custom pickup instructions remain unchanged.</p>',
        ],
    ]);
    $versionId = (int) $template->versions()->value('id');
    removeCurrentCostumeSectionFromLegacyReceiptFixture((int) $template->id, $versionId);
    $permission = Permission::findByName('ViewAny:Gear');
    $permissionAssignmentCount = DB::table('role_has_permissions')
        ->where('permission_id', $permission->id)
        ->count();

    $migration = require database_path('migrations/2026_08_17_180444_rename_costumes_to_gear.php');
    $migration->down();

    expect(Schema::hasTable('gear'))->toBeFalse()
        ->and(Schema::hasTable('costumes'))->toBeTrue();
    expect(DB::table('products')->where('id', $product->id)->value('productable_type'))
        ->toBe('App\\Models\\Costume');
    expect(DB::table('media')->where('id', $media->id)->value('model_type'))
        ->toBe('App\\Models\\Costume');
    expect(DB::table('permissions')->where('id', $permission->id)->value('name'))
        ->toBe('ViewAny:Costume');

    $legacyTemplate = DB::table('email_templates')->where('id', $template->id)->first();
    $legacySections = json_decode((string) $legacyTemplate->conditional_sections, true, flags: JSON_THROW_ON_ERROR);
    expect((string) $legacyTemplate->body)->toContain('conditional.costume')
        ->and($legacySections)->toHaveKey('costume')
        ->not->toHaveKey('gear');

    $migration->up();

    expect(Schema::hasTable('costumes'))->toBeFalse()
        ->and(Schema::hasTable('gear'))->toBeTrue()
        ->and(DB::table('gear')->where('id', $gear->id)->value('name'))->toBe('Competition Jacket')
        ->and(DB::table('products')->where('id', $product->id)->value('productable_type'))->toBe(Gear::class)
        ->and((bool) DB::table('products')->where('id', $product->id)->value('is_active'))->toBeTrue()
        ->and(DB::table('cart_items')->where('id', $cartItem->id)->value('product_id'))->toBe($product->id)
        ->and(DB::table('media')->where('id', $media->id)->value('model_type'))->toBe(Gear::class)
        ->and(DB::table('order_items')->where('id', $orderItem->id)->value('product_id'))->toBe($product->id)
        ->and(DB::table('installments')->where('id', $installment->id)->value('status'))->toBe(InstallmentStatus::Pending->value)
        ->and(DB::table('payment_plan_templates')->where('id', $paymentPlanTemplate->id)->value('product_type'))->toBe(ProductType::Gear->value)
        ->and(DB::table('gift_card_types')->where('id', $giftCardType->id)->value('restricted_to_product_type'))->toBe(ProductType::Gear->value)
        ->and(DB::table('credit_grants')->where('id', $creditGrant->id)->value('restricted_to_product_type'))->toBe(ProductType::Gear->value)
        ->and(DB::table('permissions')->where('id', $permission->id)->value('name'))->toBe('ViewAny:Gear')
        ->and(DB::table('role_has_permissions')->where('permission_id', $permission->id)->count())->toBe($permissionAssignmentCount);

    $migratedTemplate = DB::table('email_templates')->where('id', $template->id)->first();
    $migratedSections = json_decode((string) $migratedTemplate->conditional_sections, true, flags: JSON_THROW_ON_ERROR);
    $version = DB::table('email_template_versions')->where('id', $versionId)->first();
    $snapshot = json_decode((string) $version->manager_snapshot, true, flags: JSON_THROW_ON_ERROR);

    expect((string) $migratedTemplate->body)->toContain('conditional.gear')
        ->and($migratedSections)->toHaveKey('gear')
        ->not->toHaveKey('costume')
        ->and($migratedSections['gear']['en'])->toBe('<p>Custom pickup instructions remain unchanged.</p>')
        ->and((string) $version->body)->toContain('conditional.gear')
        ->and($snapshot['body']['en'])->toContain('conditional.gear')
        ->and($snapshot['conditional_sections'])->toHaveKey('gear')
        ->not->toHaveKey('costume')
        ->and($snapshot['effective_conditional_sections'])->toHaveKey('gear')
        ->not->toHaveKey('costume');
});

it('blocks the migration before renaming the table when release assumptions are unsafe', function (): void {
    $gear = Gear::factory()->create();
    $product = Product::factory()->forGear($gear)->inactive()->create();
    $migration = require database_path('migrations/2026_08_17_180444_rename_costumes_to_gear.php');

    $migration->down();

    try {
        DB::table('products')->where('id', $product->id)->update(['is_active' => true]);

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'at least one linked product is active');
        expect(Schema::hasTable('costumes'))->toBeTrue();

        DB::table('products')->where('id', $product->id)->update(['is_active' => false]);
        $pendingOrder = Order::factory()->create([
            'user_id' => auth()->id(),
            'status' => OrderStatus::Pending,
        ]);
        OrderItem::factory()->create([
            'order_id' => $pendingOrder->id,
            'product_id' => $product->id,
        ]);

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'at least one linked product has an in-flight order');

    } finally {
        if (Schema::hasTable('costumes')) {
            DB::table('products')->where('id', $product->id)->update(['is_active' => false]);
            DB::table('orders')
                ->whereIn('id', DB::table('order_items')->where('product_id', $product->id)->select('order_id'))
                ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Processing->value])
                ->update(['status' => OrderStatus::Completed->value]);

            $migration->up();
        }
    }
});

it('merges existing gear permission assignments without losing access', function (): void {
    $migration = require database_path('migrations/2026_08_17_180444_rename_costumes_to_gear.php');
    $migration->down();

    try {
        $costumePermissionId = (int) DB::table('permissions')
            ->where('name', 'ViewAny:Costume')
            ->where('guard_name', 'web')
            ->value('id');
        $gearPermissionId = (int) DB::table('permissions')->insertGetId([
            'name' => 'ViewAny:Gear',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = (int) DB::table('roles')->where('name', 'super_admin')->value('id');
        $userId = (int) auth()->id();

        DB::table('role_has_permissions')->insertOrIgnore([
            ['permission_id' => $costumePermissionId, 'role_id' => $roleId],
            ['permission_id' => $gearPermissionId, 'role_id' => $roleId],
        ]);
        DB::table('model_has_permissions')->insertOrIgnore([
            [
                'permission_id' => $costumePermissionId,
                'model_type' => App\Models\User::class,
                'model_id' => $userId,
            ],
            [
                'permission_id' => $gearPermissionId,
                'model_type' => App\Models\User::class,
                'model_id' => $userId,
            ],
        ]);

        $migration->up();

        expect(DB::table('permissions')->where('id', $costumePermissionId)->exists())->toBeFalse()
            ->and(DB::table('permissions')->where('id', $gearPermissionId)->value('name'))->toBe('ViewAny:Gear')
            ->and(DB::table('role_has_permissions')->where('permission_id', $gearPermissionId)->where('role_id', $roleId)->count())->toBe(1)
            ->and(DB::table('model_has_permissions')->where('permission_id', $gearPermissionId)->where('model_id', $userId)->count())->toBe(1);
    } finally {
        if (Schema::hasTable('costumes')) {
            $migration->up();
        }
    }
});

function suspendCurrentCostumeFeatureForLegacyMigrationTest(): void
{
    $receiptMigration = require database_path('migrations/2026_09_04_135033_add_costume_section_to_order_receipt_template.php');
    $permissionMigration = require database_path('migrations/2026_09_04_133708_grant_costume_permissions_to_owners.php');
    $pivotMigration = require database_path('migrations/2026_09_04_133025_create_product_student_assignment_table.php');
    $costumeMigration = require database_path('migrations/2026_09_04_133022_create_costumes_table.php');

    $receiptMigration->down();
    $permissionMigration->down();
    $pivotMigration->down();
    $costumeMigration->down();
}

function restoreCurrentCostumeFeatureAfterLegacyMigrationTest(): void
{
    if (Schema::hasTable('costumes')) {
        return;
    }

    $costumeMigration = require database_path('migrations/2026_09_04_133022_create_costumes_table.php');
    $pivotMigration = require database_path('migrations/2026_09_04_133025_create_product_student_assignment_table.php');
    $permissionMigration = require database_path('migrations/2026_09_04_133708_grant_costume_permissions_to_owners.php');
    $receiptMigration = require database_path('migrations/2026_09_04_135033_add_costume_section_to_order_receipt_template.php');

    $costumeMigration->up();
    $pivotMigration->up();
    $permissionMigration->up();
    $receiptMigration->up();
}

function removeCurrentCostumeSectionFromLegacyReceiptFixture(int $templateId, int $versionId): void
{
    $template = DB::table('email_templates')->where('id', $templateId)->first();
    $sections = json_decode((string) $template->conditional_sections, true, flags: JSON_THROW_ON_ERROR);
    unset($sections['costume']);
    DB::table('email_templates')->where('id', $templateId)->update([
        'conditional_sections' => json_encode($sections, JSON_THROW_ON_ERROR),
    ]);

    $version = DB::table('email_template_versions')->where('id', $versionId)->first();
    $snapshot = json_decode((string) $version->manager_snapshot, true, flags: JSON_THROW_ON_ERROR);
    unset($snapshot['conditional_sections']['costume'], $snapshot['effective_conditional_sections']['costume']);
    DB::table('email_template_versions')->where('id', $versionId)->update([
        'manager_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
    ]);
}
