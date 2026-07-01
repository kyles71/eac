<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('semester')->default(CourseSemester::Fall->value);
            $table->unsignedInteger('capacity')->default(10);
            $table->string('guest_teacher')->nullable();
            $table->timestamp('event_reminder_processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
