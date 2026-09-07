<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_productable_type_productable_id_unique');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('product_name')->nullable()->after('product_id');
        });

        DB::table('products')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    DB::table('order_items')
                        ->where('product_id', $product->id)
                        ->update(['product_name' => $product->name]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('product_name');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(
                ['productable_type', 'productable_id'],
                'products_productable_type_productable_id_unique',
            );
        });
    }
};
