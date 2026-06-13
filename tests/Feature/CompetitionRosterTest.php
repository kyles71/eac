<?php

declare(strict_types=1);

use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Student;
use App\Models\User;
use App\Services\CompetitionRosterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('uses inclusive display-timezone season dates and prevents overlaps', function (): void {
    config(['app.display_timezone' => 'America/New_York']);

    $season = CompetitionSeason::factory()->create([
        'starts_on' => now('America/New_York')->toDateString(),
        'ends_on' => now('America/New_York')->toDateString(),
    ]);

    expect($season->isCurrent())->toBeTrue()
        ->and(CompetitionSeason::query()->current()->pluck('id')->all())->toBe([$season->id]);

    expect(fn () => CompetitionSeason::factory()->create([
        'starts_on' => $season->starts_on->toDateString(),
        'ends_on' => $season->ends_on->addDay()->toDateString(),
    ]))->toThrow(ValidationException::class);
});

it('uses the first January 1 after a season starts for roster ages', function (): void {
    $season = CompetitionSeason::factory()->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-05-31',
    ]);
    $student = Student::factory()->create([
        'birthdate' => '2015-01-02',
    ]);

    expect($season->januaryFirst()->toDateString())->toBe('2027-01-01')
        ->and($student->ageOn($season->januaryFirst()))->toBe(11);
});

it('allows multiple team assignments and resolves only current competition accounts', function (): void {
    $currentSeason = CompetitionSeason::factory()->current()->create(['name' => 'Current']);
    $futureSeason = CompetitionSeason::factory()->create([
        'name' => 'Future',
        'starts_on' => now()->addYear()->toDateString(),
        'ends_on' => now()->addYears(2)->toDateString(),
    ]);
    $mini = CompetitionTeam::factory()->for($currentSeason, 'season')->create(['name' => 'Mini']);
    $junior = CompetitionTeam::factory()->for($currentSeason, 'season')->create(['name' => 'Junior']);
    $futureTeam = CompetitionTeam::factory()->for($futureSeason, 'season')->create(['name' => 'Future Team']);
    $parent = User::factory()->create();
    $student = Student::factory()->for($parent)->create();
    $staff = User::factory()->isTeacher()->create();
    $unrelatedTeacher = User::factory()->isTeacher()->create();
    $unrelatedOwner = User::factory()->isOwner()->create();
    $futureParent = User::factory()->create();
    $futureStudent = Student::factory()->for($futureParent)->create();
    $futureStaff = User::factory()->isTeacher()->create();
    $pastSeason = CompetitionSeason::factory()->ended()->create(['name' => 'Past']);
    $pastTeamId = DB::table('competition_teams')->insertGetId([
        'competition_season_id' => $pastSeason->id,
        'name' => 'Past Team',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $pastTeam = CompetitionTeam::query()->findOrFail($pastTeamId);
    $pastParent = User::factory()->create();
    $pastStudent = Student::factory()->for($pastParent)->create();
    $pastStaff = User::factory()->isTeacher()->create();

    $student->competitionTeams()->attach([$mini->id, $junior->id]);
    $staff->competitionTeams()->attach([$mini->id, $junior->id]);
    $futureStudent->competitionTeams()->attach($futureTeam);
    $futureStaff->competitionTeams()->attach($futureTeam);
    DB::table('competition_team_student')->insert([
        'competition_team_id' => $pastTeam->id,
        'student_id' => $pastStudent->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('competition_team_user')->insert([
        'competition_team_id' => $pastTeam->id,
        'user_id' => $pastStaff->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(CompetitionRosterService::class);

    expect($student->competitionTeams()->count())->toBe(2)
        ->and($staff->competitionTeams()->count())->toBe(2)
        ->and($service->isCurrentMember($parent))->toBeTrue()
        ->and($service->isCurrentMember($staff))->toBeTrue()
        ->and($service->isCurrentMember($futureParent))->toBeFalse()
        ->and($service->isCurrentMember($futureStaff))->toBeFalse()
        ->and($service->isCurrentMember($pastParent))->toBeFalse()
        ->and($service->isCurrentMember($pastStaff))->toBeFalse()
        ->and($service->isCurrentMember($unrelatedTeacher))->toBeFalse()
        ->and($service->isCurrentMember($unrelatedOwner))->toBeFalse();
});

it('requires competition staff to have a role', function (): void {
    $team = CompetitionTeam::factory()
        ->for(CompetitionSeason::factory()->current(), 'season')
        ->create();
    $user = User::factory()->create();

    expect(fn () => $team->staff()->attach($user))
        ->toThrow(ValidationException::class);
});

it('locks ended seasons teams and roster assignments', function (): void {
    $season = CompetitionSeason::factory()->ended()->create();
    $teamId = DB::table('competition_teams')->insertGetId([
        'competition_season_id' => $season->id,
        'name' => 'Historical Team',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $team = CompetitionTeam::query()->findOrFail($teamId);
    $student = Student::factory()->create();

    DB::table('competition_team_student')->insert([
        'competition_team_id' => $team->id,
        'student_id' => $student->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $season->update(['name' => 'Changed']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $team->update(['name' => 'Changed']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $student->competitionTeams()->detach($team))
        ->toThrow(ValidationException::class)
        ->and(fn () => $team->delete())
        ->toThrow(ValidationException::class)
        ->and(fn () => $season->delete())
        ->toThrow(ValidationException::class);
});

it('does not allow teams to be added to ended seasons', function (): void {
    $season = CompetitionSeason::factory()->ended()->create();

    expect(fn () => CompetitionTeam::factory()->for($season, 'season')->create())
        ->toThrow(ValidationException::class);
});

it('prevents deleting current teams and seasons with roster history', function (): void {
    $season = CompetitionSeason::factory()->current()->create();
    $team = CompetitionTeam::factory()->for($season, 'season')->create();
    $student = Student::factory()->create();
    $student->competitionTeams()->attach($team);

    expect(fn () => $team->delete())
        ->toThrow(ValidationException::class)
        ->and(fn () => $season->delete())
        ->toThrow(ValidationException::class);
});
