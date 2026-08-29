<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('academic_term_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
        });

        $now = CarbonImmutable::now((string) config('app.display_timezone', config('app.timezone')));

        DB::table('courses')
            ->select(['id', 'semester', 'created_at'])
            ->orderBy('id')
            ->chunkById(100, function ($courses) use ($now): void {
                foreach ($courses as $course) {
                    $semester = CourseSemester::tryFrom((string) $course->semester) ?? CourseSemester::Fall;
                    $year = $course->created_at === null
                        ? $now->year
                        : CarbonImmutable::parse((string) $course->created_at)->year;
                    $dates = $this->defaultDates($semester, $year);

                    DB::table('academic_terms')->updateOrInsert(
                        [
                            'semester' => $semester->value,
                            'year' => $year,
                        ],
                        [
                            ...$dates,
                            'uses_default_dates' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );

                    $academicTermId = DB::table('academic_terms')
                        ->where('semester', $semester->value)
                        ->where('year', $year)
                        ->value('id');

                    DB::table('courses')
                        ->where('id', $course->id)
                        ->update(['academic_term_id' => $academicTermId]);
                }
            });

        foreach ([$now->year, $now->year + 1] as $year) {
            foreach (CourseSemester::cases() as $semester) {
                DB::table('academic_terms')->updateOrInsert(
                    [
                        'semester' => $semester->value,
                        'year' => $year,
                    ],
                    [
                        ...$this->defaultDates($semester, $year),
                        'uses_default_dates' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('academic_term_id')->nullable(false)->change();
            $table->dropColumn('semester');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('semester')->nullable()->after('description');
        });

        DB::table('courses')
            ->select(['id', 'academic_term_id'])
            ->orderBy('id')
            ->chunkById(100, function ($courses): void {
                foreach ($courses as $course) {
                    $semester = DB::table('academic_terms')
                        ->where('id', $course->academic_term_id)
                        ->value('semester');

                    DB::table('courses')
                        ->where('id', $course->id)
                        ->update(['semester' => $semester ?? CourseSemester::Fall->value]);
                }
            });

        Schema::table('courses', function (Blueprint $table): void {
            $table->string('semester')->default(CourseSemester::Fall->value)->nullable(false)->change();
            $table->dropConstrainedForeignId('academic_term_id');
        });
    }

    /**
     * @return array{starts_on: string, ends_on: string}
     */
    private function defaultDates(CourseSemester $semester, int $year): array
    {
        $winterSpringStartsOn = CarbonImmutable::create($year, 1, 1);
        $summerStartsOn = CarbonImmutable::create($year, 6, 1);
        $fallStartsOn = CarbonImmutable::create($year, 9, 1);
        $nextWinterSpringStartsOn = CarbonImmutable::create($year + 1, 1, 1);

        return match ($semester) {
            CourseSemester::WinterSpring => [
                'starts_on' => $winterSpringStartsOn->toDateString(),
                'ends_on' => $summerStartsOn->subDay()->toDateString(),
            ],
            CourseSemester::Summer => [
                'starts_on' => $summerStartsOn->toDateString(),
                'ends_on' => $fallStartsOn->subDay()->toDateString(),
            ],
            CourseSemester::Fall => [
                'starts_on' => $fallStartsOn->toDateString(),
                'ends_on' => $nextWinterSpringStartsOn->subDay()->toDateString(),
            ],
        };
    }
};
