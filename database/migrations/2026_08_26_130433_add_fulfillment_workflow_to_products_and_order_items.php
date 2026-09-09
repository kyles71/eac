<?php

declare(strict_types=1);

use App\Enums\FulfillmentWorkflow;
use App\Models\Course;
use App\Models\GiftCardType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('fulfillment_workflow')
                ->default(FulfillmentWorkflow::Manual->value)
                ->after('send_purchase_notification');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('fulfillment_workflow')
                ->default(FulfillmentWorkflow::Manual->value)
                ->after('status');
        });

        $automaticProductIds = DB::table('products')
            ->whereIn('productable_type', [Course::class, GiftCardType::class])
            ->pluck('id');

        DB::table('products')
            ->whereIn('id', $automaticProductIds)
            ->update(['fulfillment_workflow' => FulfillmentWorkflow::Automatic->value]);

        DB::table('order_items')
            ->whereIn('product_id', $automaticProductIds)
            ->update(['fulfillment_workflow' => FulfillmentWorkflow::Automatic->value]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('fulfillment_workflow');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('fulfillment_workflow');
        });
    }
};
