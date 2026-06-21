<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->unsignedInteger('amount');
            $table->date('due_date');
            $table->string('status')->default('Pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_invoice_id')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable()->index();
            $table->string('last_payment_status')->nullable();
            $table->text('last_failure_reason')->nullable();
            $table->string('last_failure_code')->nullable();
            $table->timestamp('past_due_notification_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
