<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('status')->default('Pending');
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('total');
            $table->foreignId('discount_code_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('credit_applied')->default(0);
            $table->unsignedInteger('restricted_credit_applied')->default(0);
            $table->string('stripe_payment_intent_id')->nullable();
            $table->foreignId('payment_plan_template_id')->nullable()->constrained('payment_plan_templates');
            $table->string('payment_plan_method')->nullable();
            $table->timestamps();
        });
    }
};
