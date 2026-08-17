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
        Schema::create('recurring_private_lesson_billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_private_lesson_id')
                ->constrained(indexName: 'rpl_period_lesson_fk')
                ->cascadeOnDelete();
            $table->date('period_start');
            $table->timestamp('last_billed_at')->nullable();
            $table->foreignId('last_billed_by_user_id')
                ->nullable()
                ->constrained('users', indexName: 'rpl_period_biller_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['recurring_private_lesson_id', 'period_start'],
                'recurring_private_lesson_period_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_private_lesson_billing_periods');
    }
};
