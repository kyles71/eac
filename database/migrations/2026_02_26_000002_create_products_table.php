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
            $table->unsignedInteger('price')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('available_from')->nullable()->index();
            $table->dateTime('available_until')->nullable()->index();
            $table->boolean('include_productable_images')->default(false);
            $table->boolean('send_purchase_notification')->default(false);
            $table->foreignId('requires_course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->nullableMorphs('productable');
            $table->timestamps();

            $table->unique(['productable_type', 'productable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
