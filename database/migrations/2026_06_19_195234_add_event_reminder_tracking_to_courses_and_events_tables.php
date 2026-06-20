<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->timestamp('reminder_processed_at')->nullable()->after('cancelled_by_user_id');
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->timestamp('event_reminder_processed_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('reminder_processed_at');
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('event_reminder_processed_at');
        });
    }
};
