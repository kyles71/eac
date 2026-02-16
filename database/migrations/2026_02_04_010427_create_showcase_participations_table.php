<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('showcase_participations', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('student_id')->constrained()->onDelete('cascade');
            // $table->foreignId('form_user_id')->constrained()->onDelete('cascade');
            $table->boolean('is_participating')->default(false);
            $table->timestamps();
        });
    }
};
