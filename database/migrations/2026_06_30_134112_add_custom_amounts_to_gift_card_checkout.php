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
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('price')->nullable()->default(null)->change();
        });

        Schema::table('gift_card_types', function (Blueprint $table) {
            $table->boolean('allows_custom_amount')->default(false)->after('denomination');
            $table->unsignedInteger('minimum_custom_amount')->default(100)->after('allows_custom_amount');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedInteger('custom_gift_card_amount')->default(0)->after('quantity');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id', 'custom_gift_card_amount']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('custom_gift_card_amount')->default(0)->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('custom_gift_card_amount');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id', 'custom_gift_card_amount']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('custom_gift_card_amount');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id']);
        });

        Schema::table('gift_card_types', function (Blueprint $table) {
            $table->dropColumn(['allows_custom_amount', 'minimum_custom_amount']);
        });

        DB::table('products')
            ->whereNull('price')
            ->update(['price' => 0]);

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('price')->nullable(false)->default(0)->change();
        });
    }
};
