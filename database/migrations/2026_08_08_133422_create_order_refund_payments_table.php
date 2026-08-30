<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('order_refund_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key');
            $table->foreignId('order_refund_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_payment_intent_id');
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->unsignedInteger('amount');
            $table->string('status')->default('processing');
            $table->text('failure_reason')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->unique(
                ['order_refund_id', 'stripe_payment_intent_id'],
                'order_refund_payment_intent_unique',
            );
            $table->index('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refund_payments');
    }
};
