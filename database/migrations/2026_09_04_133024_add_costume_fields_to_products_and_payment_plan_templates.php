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
        Schema::table('products', function (Blueprint $table) {
            $table->date('order_due_on')->nullable()->after('available_until');
        });

        Schema::table('payment_plan_templates', function (Blueprint $table) {
            $table->json('costume_program_types')->nullable()->after('course_semesters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_plan_templates', function (Blueprint $table) {
            $table->dropColumn('costume_program_types');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('order_due_on');
        });
    }
};
