<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stripe_checkout_session_id');
            $table->foreignId('payment_plan_template_id')->nullable()->constrained('payment_plan_templates');
            $table->string('payment_plan_method')->nullable();
        });
    }
};
