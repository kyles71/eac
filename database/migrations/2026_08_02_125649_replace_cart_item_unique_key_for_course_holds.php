<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id', 'custom_gift_card_amount']);
            $table->unsignedBigInteger('course_hold_key')
                ->virtualAs('COALESCE(course_hold_id, 0)')
                ->after('course_hold_id');
            $table->unique(
                ['user_id', 'product_id', 'custom_gift_card_amount', 'course_hold_key'],
                'cart_items_unique_line',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_items_unique_line');
            $table->dropColumn('course_hold_key');
            $table->unique(['user_id', 'product_id', 'custom_gift_card_amount']);
        });
    }
};
