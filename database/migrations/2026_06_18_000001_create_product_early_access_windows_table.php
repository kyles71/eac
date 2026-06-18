<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dateTime('available_from')->nullable()->index();
            $table->dateTime('available_until')->nullable()->index();
        });

        Schema::create('product_early_access_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->dateTime('available_from');
            $table->dateTime('available_until')->nullable();
            $table->json('audiences')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'available_from']);
            $table->index(['product_id', 'available_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_early_access_windows');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['available_from']);
            $table->dropIndex(['available_until']);
            $table->dropColumn([
                'available_from',
                'available_until',
            ]);
        });
    }
};
