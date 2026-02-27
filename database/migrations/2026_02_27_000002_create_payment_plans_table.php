<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_plan_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->unsignedInteger('total_amount');
            $table->unsignedSmallInteger('number_of_installments');
            $table->string('frequency');
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_payment_method_id')->nullable();
            $table->timestamps();
        });
    }
};
