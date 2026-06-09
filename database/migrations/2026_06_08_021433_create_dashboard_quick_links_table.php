<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_quick_links', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('audience');
            $table->string('destination');
            $table->text('external_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['audience', 'is_active', 'sort_order']);
        });
    }
};
