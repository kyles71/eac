<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('starts_in_year')->unique();
            $table->timestamps();
        });

        Schema::table('academic_terms', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedInteger('target_enrollments')->nullable()->after('uses_default_dates');
            $table->unsignedInteger('stretch_goal_enrollments')->nullable()->after('target_enrollments');
        });

        DB::table('academic_terms')
            ->select(['id', 'semester', 'year'])
            ->orderBy('id')
            ->eachById(function (object $term): void {
                $startsInYear = $term->semester === CourseSemester::Fall->value
                    ? (int) $term->year
                    : (int) $term->year - 1;

                DB::table('academic_years')->insertOrIgnore([
                    'starts_in_year' => $startsInYear,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('academic_terms')
                    ->where('id', $term->id)
                    ->update([
                        'academic_year_id' => DB::table('academic_years')
                            ->where('starts_in_year', $startsInYear)
                            ->value('id'),
                    ]);
            });

        Schema::table('academic_terms', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropColumn(['target_enrollments', 'stretch_goal_enrollments']);
        });

        Schema::dropIfExists('academic_years');
    }
};
