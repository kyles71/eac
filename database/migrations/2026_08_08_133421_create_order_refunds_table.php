<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->text('reason');
            $table->boolean('cancel_remaining_installments')->default(false);
            $table->boolean('restore_store_credit')->default(false);
            $table->json('enrollment_ids')->nullable();
            $table->json('enrollment_details')->nullable();
            $table->string('status')->default('Processing');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('enrollments_removed_at')->nullable();
            $table->timestamp('installments_cancelled_at')->nullable();
            $table->timestamp('credit_restored_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
    }
};
