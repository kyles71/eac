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
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('payment_plan_principal')
                ->default(0)
                ->after('payment_plan_fee');
            $table->unsignedInteger('payment_plan_subtotal')
                ->default(0)
                ->after('payment_plan_principal');
            $table->unsignedInteger('payment_plan_discount_amount')
                ->default(0)
                ->after('payment_plan_subtotal');
            $table->unsignedInteger('payment_plan_restricted_credit_applied')
                ->default(0)
                ->after('payment_plan_discount_amount');
            $table->unsignedInteger('payment_plan_credit_applied')
                ->default(0)
                ->after('payment_plan_restricted_credit_applied');
        });

        DB::table('orders')
            ->whereNotNull('payment_plan_template_id')
            ->update([
                'payment_plan_principal' => DB::raw('CASE WHEN total > payment_plan_fee THEN total - payment_plan_fee ELSE 0 END'),
                'payment_plan_subtotal' => DB::raw('CASE WHEN total > payment_plan_fee THEN total - payment_plan_fee ELSE 0 END'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_plan_principal',
                'payment_plan_subtotal',
                'payment_plan_discount_amount',
                'payment_plan_restricted_credit_applied',
                'payment_plan_credit_applied',
            ]);
        });
    }
};
