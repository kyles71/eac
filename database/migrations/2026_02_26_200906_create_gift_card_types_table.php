<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('gift_card_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('denomination');
            $table->boolean('allows_custom_amount')->default(false);
            $table->unsignedInteger('minimum_custom_amount')->default(100);
            $table->string('restricted_to_product_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_types');
    }
};
