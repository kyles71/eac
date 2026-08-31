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
        Schema::create('recurring_private_lesson_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_private_lesson_charge_id')
                ->unique('rpl_coverage_charge_unique')
                ->constrained('recurring_private_lesson_charges', indexName: 'rpl_coverage_charge_fk')
                ->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status')->default('Active');
            $table->unsignedInteger('gross_amount');
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('restricted_credit_amount')->default(0);
            $table->unsignedInteger('credit_amount')->default(0);
            $table->unsignedInteger('stripe_amount')->default(0);
            $table->string('stripe_refund_id')
                ->nullable()
                ->unique('rpl_coverage_stripe_refund_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_private_lesson_coverages');
    }
};
