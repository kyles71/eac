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
            $table->unsignedInteger('payment_plan_fee')->default(0)->after('restricted_credit_applied');
            $table->foreignId('payment_plan_terms_version_id')
                ->nullable()
                ->after('payment_plan_template_id')
                ->constrained('legal_document_versions')
                ->nullOnDelete();
        });
    }
};
