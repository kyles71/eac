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
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('semester')
                ->default(CourseSemester::Fall->value)
                ->after('description');
        });
    }
};
