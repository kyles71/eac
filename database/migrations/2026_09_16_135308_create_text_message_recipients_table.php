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
        Schema::create('text_message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('text_message_batches')->cascadeOnDelete();
            $table->string('phone', 16);
            $table->json('sources');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->json('provider_receipt')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'phone']);
            $table->index(['status', 'available_at']);
            $table->index(['status', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('text_message_recipients');
    }
};
