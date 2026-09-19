<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_student_assignment', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['product_id', 'student_id']);
        });

        Schema::create('product_student_exclusion', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['product_id', 'student_id']);
        });

        Schema::create('product_purchase_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('reminder_on');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['product_id', 'user_id', 'reminder_on'], 'product_purchase_reminder_delivery_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_purchase_reminder_deliveries');
        Schema::dropIfExists('product_student_exclusion');
        Schema::dropIfExists('product_student_assignment');
    }
};
