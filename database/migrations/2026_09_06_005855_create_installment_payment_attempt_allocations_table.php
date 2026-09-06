<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('installment_payment_attempt_allocations');

        Schema::create('installment_payment_attempt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_payment_attempt_id');
            $table->foreign('installment_payment_attempt_id', 'ipa_attempt_fk')
                ->references('id')
                ->on('installment_payment_attempts')
                ->cascadeOnDelete();
            $table->foreignId('installment_id');
            $table->foreign('installment_id', 'ipa_installment_fk')
                ->references('id')
                ->on('installments')
                ->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->timestamps();

            $table->unique(
                ['installment_payment_attempt_id', 'installment_id'],
                'installment_payment_attempt_allocation_unique',
            );
            $table->index(['installment_id', 'installment_payment_attempt_id'], 'installment_payment_attempt_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payment_attempt_allocations');
    }
};
