<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('form_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->nullable()->constrained()->onDelete('cascade');
            $table->nullableMorphs('responseable');
            $table->string('signature')->nullable();
            $table->date('date_signed')->nullable();
            $table->timestamps();

            $table->index(['form_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_users');
    }
};
