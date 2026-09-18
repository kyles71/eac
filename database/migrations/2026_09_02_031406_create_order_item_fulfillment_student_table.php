<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_fulfillment_student', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_fulfillment_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['order_item_fulfillment_id', 'student_id'],
                'fulfillment_student_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_fulfillment_student');
    }
};
