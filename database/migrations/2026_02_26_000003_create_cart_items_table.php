<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('custom_gift_card_amount')->default(0);
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'product_id']);
            $table->unique(['user_id', 'product_id', 'custom_gift_card_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
