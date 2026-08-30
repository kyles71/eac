<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('student_communications', function (Blueprint $table): void {
            $table->string('first_aid_type')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('student_communications', function (Blueprint $table): void {
            $table->dropColumn('first_aid_type');
        });
    }
};
