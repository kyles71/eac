<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('form_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_id')->constrained()->onDelete('cascade');
            $table->foreignId('form_version_id')->constrained()->onDelete('cascade');
            $table->morphs('respondent');
            $table->nullableMorphs('subject');
            $table->timestamps();

            $table->index(['form_id', 'respondent_type', 'respondent_id']);
            $table->index(['form_id', 'subject_type', 'subject_id']);
        });

        Schema::create('form_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('revision_of_id')->nullable()->constrained('form_responses')->nullOnDelete();
            $table->string('status');
            $table->string('signature')->nullable();
            $table->date('date_signed')->nullable();
            $table->nullableMorphs('projection');
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['form_assignment_id', 'form_version_id', 'status']);
        });

        Schema::create('form_answer_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_response_id')->constrained()->cascadeOnDelete();
            $table->uuid('block_key');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['form_response_id', 'block_key', 'position']);
            $table->index(['form_id', 'block_key']);
        });

        Schema::create('form_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_response_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_answer_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('value_string')->nullable();
            $table->text('value_text')->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->decimal('value_decimal', 16, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->timestamps();

            $table->index(['form_field_id', 'value_string']);
            $table->index(['form_field_id', 'value_integer']);
            $table->index(['form_field_id', 'value_decimal']);
            $table->index(['form_field_id', 'value_boolean']);
            $table->index(['form_field_id', 'value_date']);
            $table->index(['form_field_id', 'value_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_answers');
        Schema::dropIfExists('form_answer_groups');
        Schema::dropIfExists('form_responses');
        Schema::dropIfExists('form_assignments');
    }
};
