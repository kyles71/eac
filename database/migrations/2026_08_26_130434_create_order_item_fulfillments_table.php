<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('unit_number');
            $table->nullableMorphs('source');
            $table->foreignId('fulfilled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at');
            $table->text('note')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['order_item_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_fulfillments');
    }
};
