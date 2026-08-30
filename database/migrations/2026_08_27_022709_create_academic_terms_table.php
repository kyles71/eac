<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('semester');
            $table->unsignedSmallInteger('year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('uses_default_dates')->default(true);
            $table->timestamps();

            $table->unique(['semester', 'year']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};
