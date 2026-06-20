<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('product_question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_question_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('unit_number');
            $table->text('question');
            $table->string('question_type');
            $table->boolean('was_required');
            $table->unsignedInteger('question_order')->default(0);
            $table->string('selected_option')->nullable();
            $table->text('answer')->nullable();
            $table->timestamps();

            $table->unique(
                ['order_item_id', 'product_question_id', 'unit_number'],
                'pqa_item_question_unit_unique',
            );
        });
    }
};
