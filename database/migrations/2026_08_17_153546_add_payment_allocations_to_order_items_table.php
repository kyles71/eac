<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('discount_allocated')->default(0)->after('total_price');
            $table->unsignedInteger('restricted_credit_allocated')->default(0)->after('discount_allocated');
            $table->unsignedInteger('credit_allocated')->default(0)->after('restricted_credit_allocated');
            $table->unsignedInteger('stripe_allocated')->default(0)->after('credit_allocated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'discount_allocated',
                'restricted_credit_allocated',
                'credit_allocated',
                'stripe_allocated',
            ]);
        });
    }
};
