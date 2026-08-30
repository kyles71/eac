<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('installment_due_date_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('adjustment_batch_uuid')->index();
            $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjusted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('old_due_date');
            $table->date('new_due_date');
            $table->string('previous_status');
            $table->unsignedSmallInteger('previous_retry_count');
            $table->text('reason');
            $table->string('customer_notification_status');
            $table->text('customer_notification_note')->nullable();
            $table->timestamps();

            $table->index(['installment_id', 'created_at']);
        });

        $superAdmin = Role::findOrCreate(Role::SUPER_ADMIN, 'web');
        $owner = Role::findOrCreate('owner', 'web');

        $superAdmin->givePermissionTo(Permission::findOrCreate('AdjustDueDates:PaymentPlan', 'web'));
        $owner->givePermissionTo(Permission::findOrCreate('AdjustDueDates:PaymentPlan', 'web'));
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_due_date_adjustments');
    }
};
