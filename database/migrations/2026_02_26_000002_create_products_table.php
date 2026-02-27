<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('requires_course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->nullableMorphs('productable');
            $table->timestamps();

            $table->unique(['productable_type', 'productable_id']);
        });
    }
};
