<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('event_attendees', function (Blueprint $table): void {
            $table->string('status')->nullable()->after('attendee_id');
            $table->dropColumn('attended');
        });
    }

    public function down(): void
    {
        Schema::table('event_attendees', function (Blueprint $table): void {
            $table->boolean('attended')->default(false)->after('attendee_id');
            $table->dropColumn('status');
        });
    }
};
