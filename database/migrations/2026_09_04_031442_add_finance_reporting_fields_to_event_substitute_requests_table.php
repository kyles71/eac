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
        Schema::table('event_substitute_requests', function (Blueprint $table): void {
            $table->string('reason_type')->nullable()->after('status')->index();
            $table->foreignId('sick_instructor_id')
                ->nullable()
                ->after('requested_by_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_substitute_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sick_instructor_id');
            $table->dropColumn('reason_type');
        });
    }
};
