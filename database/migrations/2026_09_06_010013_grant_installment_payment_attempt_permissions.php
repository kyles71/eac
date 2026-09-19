<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class() extends Migration
{
    public function up(): void
    {
        $roles = [
            Role::findOrCreate(Role::SUPER_ADMIN, 'web'),
            Role::findOrCreate(Role::OWNER, 'web'),
        ];

        foreach ($roles as $role) {
            $role->givePermissionTo([
                Permission::findOrCreate('RetryPayment:PaymentPlan', 'web'),
                Permission::findOrCreate('SendPaymentLink:PaymentPlan', 'web'),
            ]);
        }
    }

    public function down(): void
    {
        Permission::query()
            ->whereIn('name', [
                'RetryPayment:PaymentPlan',
                'SendPaymentLink:PaymentPlan',
            ])
            ->where('guard_name', 'web')
            ->delete();
    }
};
