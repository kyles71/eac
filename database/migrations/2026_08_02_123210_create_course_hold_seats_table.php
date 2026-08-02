<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('course_hold_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_hold_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('locked_unit_price');
            $table->foreignId('claimed_order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['course_id', 'released_at']);
            $table->index(['course_hold_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_hold_seats');
    }
};
