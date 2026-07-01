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

        if (! Schema::hasColumn('gift_card_types', 'allows_custom_amount')) {
            Schema::table('gift_card_types', function (Blueprint $table) {
                $table->boolean('allows_custom_amount')->default(false)->after('denomination');
            });
        }

        if (! Schema::hasColumn('gift_card_types', 'minimum_custom_amount')) {
            Schema::table('gift_card_types', function (Blueprint $table) {
                $table->unsignedInteger('minimum_custom_amount')->default(100)->after('allows_custom_amount');
            });
        }

        if (! Schema::hasIndex('cart_items', 'cart_items_user_id_product_id_index')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->index(['user_id', 'product_id']);
            });
        }

        if (Schema::hasIndex('cart_items', 'cart_items_user_id_product_id_unique')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'product_id']);
            });
        }

        if (! Schema::hasColumn('cart_items', 'custom_gift_card_amount')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->unsignedInteger('custom_gift_card_amount')->default(0)->after('quantity');
            });
        }

        if (! Schema::hasIndex('cart_items', 'cart_items_user_id_product_id_custom_gift_card_amount_unique')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->unique(['user_id', 'product_id', 'custom_gift_card_amount']);
            });
        }

        if (! Schema::hasColumn('order_items', 'custom_gift_card_amount')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->unsignedInteger('custom_gift_card_amount')->default(0)->after('total_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'custom_gift_card_amount')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('custom_gift_card_amount');
            });
        }

        if (Schema::hasIndex('cart_items', 'cart_items_user_id_product_id_custom_gift_card_amount_unique')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'product_id', 'custom_gift_card_amount']);
            });
        }

        if (Schema::hasColumn('cart_items', 'custom_gift_card_amount')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropColumn('custom_gift_card_amount');
            });
        }

        if (! Schema::hasIndex('cart_items', 'cart_items_user_id_product_id_unique')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->unique(['user_id', 'product_id']);
            });
        }

        if (Schema::hasIndex('cart_items', 'cart_items_user_id_product_id_index')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'product_id']);
            });
        }

        if (Schema::hasColumn('gift_card_types', 'minimum_custom_amount')) {
            Schema::table('gift_card_types', function (Blueprint $table) {
                $table->dropColumn('minimum_custom_amount');
            });
        }

        if (Schema::hasColumn('gift_card_types', 'allows_custom_amount')) {
            Schema::table('gift_card_types', function (Blueprint $table) {
                $table->dropColumn('allows_custom_amount');
            });
        }

        DB::table('products')
            ->whereNull('price')
            ->update(['price' => 0]);

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('price')->nullable(false)->default(0)->change();
        });
    }
};
