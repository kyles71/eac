<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('student_waivers', function (Blueprint $table) {
            $table->id();
            $table->text('medical_conditions')->nullable();
            $table->text('allergies')->nullable();
            $table->timestamps();
        });
    }
};
