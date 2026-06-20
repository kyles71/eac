<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table): void {
            $table->timestamp('last_attempted_at')->nullable()->after('retry_count')->index();
            $table->string('last_payment_status')->nullable()->after('last_attempted_at');
            $table->text('last_failure_reason')->nullable()->after('last_payment_status');
            $table->string('last_failure_code')->nullable()->after('last_failure_reason');
            $table->timestamp('past_due_notification_sent_at')->nullable()->after('last_failure_code');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table): void {
            $table->dropColumn([
                'last_attempted_at',
                'last_payment_status',
                'last_failure_reason',
                'last_failure_code',
                'past_due_notification_sent_at',
            ]);
        });
    }
};
