<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('event_substitute_coverages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('covered_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('substitute_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('needed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_reason')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'closed_at']);
            $table->unique(['event_id', 'covered_teacher_id']);
            $table->index(['covered_teacher_id', 'closed_at']);
            $table->index(['substitute_teacher_id', 'closed_at']);
        });

        Schema::table('event_substitute_requests', function (Blueprint $table): void {
            $table->foreignId('event_substitute_coverage_id')
                ->nullable()
                ->after('event_id')
                ->constrained('event_substitute_coverages')
                ->nullOnDelete();
            $table->index(
                ['event_substitute_coverage_id', 'status'],
                'substitute_requests_coverage_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('event_substitute_requests', function (Blueprint $table): void {
            $table->dropIndex('substitute_requests_coverage_status_index');
            $table->dropConstrainedForeignId('event_substitute_coverage_id');
        });

        Schema::dropIfExists('event_substitute_coverages');
    }
};
