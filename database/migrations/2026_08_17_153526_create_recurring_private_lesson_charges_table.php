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
        Schema::create('recurring_private_lesson_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_private_lesson_id')
                ->constrained(indexName: 'rpl_charge_lesson_fk')
                ->cascadeOnDelete();
            $table->foreignId('recurring_private_lesson_billing_period_id')
                ->constrained(indexName: 'rpl_charge_period_fk')
                ->cascadeOnDelete();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('Scheduled');
            $table->unsignedInteger('amount');
            $table->timestamp('billed_at')->nullable();
            $table->foreignId('billed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('seven_day_reminder_sent_at')->nullable();
            $table->timestamp('two_day_reminder_sent_at')->nullable();
            $table->json('reschedule_history')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_type')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('automatically_cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['recurring_private_lesson_id', 'status'], 'recurring_private_lesson_charge_status');
            $table->index(['status', 'billed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_private_lesson_charges');
    }
};
