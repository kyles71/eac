<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('form_builder_assignments', function (Blueprint $table): void {
            $table->boolean('is_manually_assigned')->default(false)->after('subject_id');
            $table->foreignId('manually_assigned_by_id')
                ->nullable()
                ->after('is_manually_assigned')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('manually_assigned_at')->nullable()->after('manually_assigned_by_id');
            $table->unique(
                ['form_id', 'subject_type', 'subject_id'],
                'form_assignments_form_subject_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('form_builder_assignments', function (Blueprint $table): void {
            $table->dropUnique('form_assignments_form_subject_unique');
            $table->dropConstrainedForeignId('manually_assigned_by_id');
            $table->dropColumn(['is_manually_assigned', 'manually_assigned_at']);
        });
    }
};
