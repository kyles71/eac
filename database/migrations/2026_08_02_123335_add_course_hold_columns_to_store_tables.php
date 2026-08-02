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
            $table->foreignId('course_hold_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('held_unit_price')->nullable()->after('custom_gift_card_amount');
            $table->index(['user_id', 'product_id', 'course_hold_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('course_hold_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('hold_checkout_expires_at')->nullable()->after('stripe_payment_intent_id')->index();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('course_hold_seat_id')->nullable()->after('order_item_id')->constrained()->nullOnDelete();
            $table->unique('course_hold_seat_id');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique(['course_hold_seat_id']);
            $table->dropConstrainedForeignId('course_hold_seat_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['hold_checkout_expires_at']);
            $table->dropColumn('hold_checkout_expires_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_hold_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'product_id', 'course_hold_id']);
            $table->dropConstrainedForeignId('course_hold_id');
            $table->dropColumn('held_unit_price');
        });
    }
};
