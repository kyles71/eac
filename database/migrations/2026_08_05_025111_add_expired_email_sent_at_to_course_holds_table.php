<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('course_holds', function (Blueprint $table) {
            $table->timestamp('expired_email_sent_at')->nullable()->after('reminder_sent_at');
            $table->index(['expired_email_sent_at', 'expires_at']);
        });

        DB::table('course_holds')
            ->where('expires_at', '<=', now())
            ->update(['expired_email_sent_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('course_holds', function (Blueprint $table) {
            $table->dropIndex(['expired_email_sent_at', 'expires_at']);
            $table->dropColumn('expired_email_sent_at');
        });
    }
};
