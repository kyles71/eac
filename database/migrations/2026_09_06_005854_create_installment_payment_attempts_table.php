<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('installment_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('origin');
            $table->string('status');
            $table->unsignedInteger('total_amount');
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_payment_method_id')->nullable();
            $table->string('stripe_status')->nullable();
            $table->boolean('use_for_future')->default(false);
            $table->text('failure_reason')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('advice_code')->nullable();
            $table->timestamp('failure_recorded_at')->nullable();
            $table->string('success_email_status')->nullable();
            $table->timestamp('success_email_processing_at')->nullable();
            $table->timestamp('success_email_sent_at')->nullable();
            $table->string('failure_email_status')->nullable();
            $table->timestamp('failure_email_processing_at')->nullable();
            $table->timestamp('failure_email_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_plan_id', 'status']);
            $table->index(['status', 'updated_at']);
            $table->index(['success_email_status', 'id']);
            $table->index(['failure_email_status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payment_attempts');
    }
};
