<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('student_communications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('stop_light_color')->nullable();
            $table->dateTime('occurred_at');
            $table->text('note');
            $table->json('recipient_emails');
            $table->timestamp('queued_at');
            $table->timestamps();

            $table->index(['student_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_communications');
    }
};
