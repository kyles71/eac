<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('event_substitute_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('response_recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->text('request_reason')->nullable();
            $table->text('response_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('reminder_processed_at')->nullable();
            $table->timestamp('release_requested_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_reason')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['teacher_id', 'status']);
            $table->index(['status', 'reminder_processed_at', 'created_at'], 'substitute_requests_reminder_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_substitute_requests');
    }
};
