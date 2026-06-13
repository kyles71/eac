<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use App\Filament\Admin\Resources\CompetitionSeasons\Pages\CreateCompetitionSeason;
use App\Filament\Admin\Resources\CompetitionSeasons\Pages\ListCompetitionSeasons;
use App\Filament\Admin\Resources\CompetitionSeasons\Pages\ViewCompetitionSeason;
use App\Filament\Admin\Resources\CompetitionSeasons\RelationManagers\TeamsRelationManager;
use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use App\Filament\Admin\Resources\CompetitionTeams\Pages\CreateCompetitionTeam;
use App\Filament\Admin\Resources\CompetitionTeams\Pages\ListCompetitionTeams;
use App\Filament\Admin\Resources\CompetitionTeams\Pages\ViewCompetitionTeam;
use App\Filament\Admin\Resources\CompetitionTeams\RelationManagers\StaffRelationManager;
use App\Filament\Admin\Resources\CompetitionTeams\RelationManagers\StudentsRelationManager;
use App\Filament\Admin\Resources\Students\Pages\ViewStudent;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('manages competition seasons and teams from a dedicated navigation group', function (): void {
    livewire(CreateCompetitionSeason::class)
        ->fillForm([
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $season = CompetitionSeason::query()->where('name', '2026-2027')->firstOrFail();

    livewire(CreateCompetitionTeam::class)
        ->fillForm([
            'competition_season_id' => $season->id,
            'name' => 'Elite',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas(CompetitionTeam::class, [
        'competition_season_id' => $season->id,
        'name' => 'Elite',
    ]);

    expect(CompetitionSeasonResource::getNavigationGroup())->toBe('Competition')
        ->and(CompetitionTeamResource::getNavigationGroup())->toBe('Competition');

    livewire(ListCompetitionSeasons::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$season])
        ->assertTableColumnExists('status');

    livewire(ListCompetitionTeams::class)
        ->loadTable()
        ->assertCanSeeTableRecords(CompetitionTeam::all())
        ->assertTableColumnExists('students_count')
        ->assertTableColumnExists('staff_count');
});

it('shows competition membership history on admin student and staff records', function (): void {
    $season = CompetitionSeason::factory()->current()->create(['name' => '2026-2027']);
    $team = CompetitionTeam::factory()->for($season, 'season')->create(['name' => 'Elite']);
    $parent = User::factory()->create();
    $student = Student::factory()->for($parent)->create();
    $staff = User::factory()->isTeacher()->create();

    $student->competitionTeams()->attach($team);
    $staff->competitionTeams()->attach($team);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->assertSee('Competition Membership')
        ->assertSee('2026-2027')
        ->assertSee('Elite');

    livewire(ViewUser::class, ['record' => $staff->id])
        ->assertSee('Competition Membership')
        ->assertSee('2026-2027')
        ->assertSee('Elite');
});

it('only shows roster email actions on competition list records and prepopulates all members', function (): void {
    $season = CompetitionSeason::factory()->current()->create();
    $firstTeam = CompetitionTeam::factory()->for($season, 'season')->create(['name' => 'Mini']);
    $secondTeam = CompetitionTeam::factory()->for($season, 'season')->create(['name' => 'Junior']);
    $student = Student::factory()->create([
        'first_name' => 'Avery',
        'last_name' => 'Student',
    ]);
    $firstStaff = User::factory()->isTeacher()->create([
        'first_name' => 'Bailey',
        'last_name' => 'Teacher',
    ]);
    $secondStaff = User::factory()->isTeacher()->create([
        'first_name' => 'Casey',
        'last_name' => 'Teacher',
    ]);

    $student->competitionTeams()->attach([$firstTeam->id, $secondTeam->id]);
    $firstStaff->competitionTeams()->attach($firstTeam);
    $secondStaff->competitionTeams()->attach($secondTeam);

    $seasonList = livewire(ListCompetitionSeasons::class)->loadTable();
    $teamList = livewire(ListCompetitionTeams::class)->loadTable();

    expect($seasonList->instance()->getTable()->getActions())->toHaveCount(1)
        ->and($teamList->instance()->getTable()->getActions())->toHaveCount(1)
        ->and($seasonList->instance()->getTable()->getRecordUrl($season))->toBe(CompetitionSeasonResource::getUrl('view', ['record' => $season]))
        ->and($teamList->instance()->getTable()->getRecordUrl($firstTeam))->toBe(CompetitionTeamResource::getUrl('view', ['record' => $firstTeam]));

    $seasonList
        ->mountAction(TestAction::make('sendEmail')->table($season))
        ->assertActionDataSet([
            'to' => [
                "student:{$student->id}",
                "teacher:{$firstStaff->id}",
                "teacher:{$secondStaff->id}",
            ],
        ]);

    $teamList
        ->mountAction(TestAction::make('sendEmail')->table($firstTeam))
        ->assertActionDataSet([
            'to' => [
                "student:{$student->id}",
                "teacher:{$firstStaff->id}",
            ],
        ]);
});

it('edits season and team details from view page slideovers', function (): void {
    $season = CompetitionSeason::factory()->current()->create();
    $team = CompetitionTeam::factory()->for($season, 'season')->create();

    livewire(ViewCompetitionSeason::class, ['record' => $season->id])
        ->assertActionExists(
            EditAction::class,
            fn (Action $action): bool => $action->isModalSlideOver() && $action->getUrl() === null,
        )
        ->mountAction(EditAction::class)
        ->assertSchemaComponentExists('name')
        ->assertSchemaComponentExists('starts_on')
        ->assertSchemaComponentExists('ends_on')
        ->setActionData([
            'name' => 'Updated Season',
            'starts_on' => $season->starts_on->toDateString(),
            'ends_on' => $season->ends_on->toDateString(),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    livewire(ViewCompetitionTeam::class, ['record' => $team->id])
        ->assertActionExists(
            EditAction::class,
            fn (Action $action): bool => $action->isModalSlideOver() && $action->getUrl() === null,
        )
        ->mountAction(EditAction::class)
        ->assertSchemaComponentExists('competition_season_id')
        ->assertSchemaComponentExists('name')
        ->setActionData([
            'competition_season_id' => $season->id,
            'name' => 'Updated Team',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($season->refresh()->name)->toBe('Updated Season')
        ->and($team->refresh()->name)->toBe('Updated Team');
});

it('creates and name-only edits teams from the season view relation manager', function (): void {
    $season = CompetitionSeason::factory()->current()->create();
    $team = CompetitionTeam::factory()->for($season, 'season')->create(['name' => 'Mini']);

    livewire(TeamsRelationManager::class, [
        'ownerRecord' => $season,
        'pageClass' => ViewCompetitionSeason::class,
    ])
        ->assertActionExists(
            TestAction::make(CreateAction::class)->table(),
            fn (Action $action): bool => $action->isModalSlideOver() && $action->getUrl() === null,
        )
        ->assertActionExists(
            TestAction::make(EditAction::class)->table($team),
            fn (Action $action): bool => $action->isModalSlideOver() && $action->getUrl() === null,
        )
        ->mountAction(TestAction::make(CreateAction::class)->table())
        ->assertSchemaComponentExists('name')
        ->assertSchemaComponentDoesNotExist('competition_season_id')
        ->setActionData(['name' => 'Junior'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->mountAction(TestAction::make(EditAction::class)->table($team))
        ->assertSchemaComponentExists('name')
        ->assertSchemaComponentDoesNotExist('competition_season_id')
        ->setActionData(['name' => 'Mini Elite'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    assertDatabaseHas(CompetitionTeam::class, [
        'competition_season_id' => $season->id,
        'name' => 'Junior',
    ]);

    expect($team->refresh()->name)->toBe('Mini Elite');
});

it('manages and emails individual team roster members from the team view', function (): void {
    $season = CompetitionSeason::factory()->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-05-31',
    ]);
    $team = CompetitionTeam::factory()->for($season, 'season')->create();
    $student = Student::factory()->create(['birthdate' => '2015-01-02']);
    $staff = User::factory()->isTeacher()->create();

    $student->competitionTeams()->attach($team);
    $staff->competitionTeams()->attach($team);

    livewire(StudentsRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewCompetitionTeam::class,
    ])
        ->loadTable()
        ->assertActionVisible(TestAction::make(AttachAction::class)->table())
        ->assertActionVisible(TestAction::make(DetachAction::class)->table($student))
        ->assertTableColumnStateSet('age_as_of_january_first', 11, $student)
        ->mountAction(TestAction::make('sendEmail')->table($student))
        ->assertActionDataSet(['to' => ["student:{$student->id}"]])
        ->unmountAction()
        ->callAction(TestAction::make(DetachAction::class)->table($student))
        ->assertHasNoActionErrors();

    livewire(StaffRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewCompetitionTeam::class,
    ])
        ->loadTable()
        ->assertActionVisible(TestAction::make(AttachAction::class)->table())
        ->assertActionVisible(TestAction::make(DetachAction::class)->table($staff))
        ->mountAction(TestAction::make('sendEmail')->table($staff))
        ->assertActionDataSet(['to' => ["teacher:{$staff->id}"]])
        ->unmountAction()
        ->callAction(TestAction::make(DetachAction::class)->table($staff))
        ->assertHasNoActionErrors();

    expect($team->students()->exists())->toBeFalse()
        ->and($team->staff()->exists())->toBeFalse();
});

it('keeps ended competition relation managers read only while allowing individual emails', function (): void {
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

    livewire(TeamsRelationManager::class, [
        'ownerRecord' => $season,
        'pageClass' => ViewCompetitionSeason::class,
    ])
        ->assertActionHidden(TestAction::make(CreateAction::class)->table())
        ->assertActionHidden(TestAction::make(EditAction::class)->table($team));

    livewire(StudentsRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewCompetitionTeam::class,
    ])
        ->assertActionHidden(TestAction::make(AttachAction::class)->table())
        ->assertActionHidden(TestAction::make(DetachAction::class)->table($student))
        ->assertActionVisible(TestAction::make('sendEmail')->table($student));
});

it('only offers role-bearing users in the competition staff selector', function (): void {
    $team = CompetitionTeam::factory()
        ->for(CompetitionSeason::factory()->current(), 'season')
        ->create();
    $staff = User::factory()->isTeacher()->create();
    $plainUser = User::factory()->create();

    livewire(StaffRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewCompetitionTeam::class,
    ])
        ->mountAction(TestAction::make(AttachAction::class)->table())
        ->assertSchemaComponentExists(
            'recordId',
            checkComponentUsing: function (Select $select) use ($plainUser, $staff): bool {
                $options = $select->getOptions();

                return isset($options[$staff->id])
                    && ! isset($options[$plainUser->id]);
            },
        );
});

it('grants competition resource permissions to the seeded super admin role', function (): void {
    $superAdmin = Role::findByName('super_admin');

    expect($superAdmin->hasPermissionTo('ViewAny:CompetitionSeason'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('Create:CompetitionSeason'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('ViewAny:CompetitionTeam'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('Update:CompetitionTeam'))->toBeTrue();
});
