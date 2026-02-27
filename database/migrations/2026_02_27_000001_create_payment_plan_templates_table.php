<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plan_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type');
            $table->unsignedInteger('min_price');
            $table->unsignedInteger('max_price');
            $table->unsignedSmallInteger('number_of_installments');
            $table->string('frequency');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
