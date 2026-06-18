<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('product_early_access_window_user', function (Blueprint $table) {
            $table->foreignId('product_early_access_window_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['product_early_access_window_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_early_access_window_user');
    }
};
