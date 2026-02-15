<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_waiver_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('relationship');
            $table->string('phone_number');
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }
};
