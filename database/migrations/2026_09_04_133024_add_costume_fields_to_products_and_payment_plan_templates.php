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
            $table->boolean('is_purchase_required')->default(false)->after('available_until');
            $table->date('purchase_reminder_on')->nullable()->after('is_purchase_required');
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

        $productColumns = array_values(array_filter(
            ['order_due_on', 'is_purchase_required', 'purchase_reminder_on'],
            fn (string $column): bool => Schema::hasColumn('products', $column),
        ));

        if ($productColumns !== []) {
            Schema::table('products', function (Blueprint $table) use ($productColumns) {
                $table->dropColumn($productColumns);
            });
        }
    }
};
