<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\Calendar;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Tags\Tag;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function () {
    /* The TestCase setup generates a user before each test, so we need to clear the table to make sure we have a clean slate. */
    User::truncate();
});

it('can render the index page', function () {
    livewire(ListUsers::class)
        ->assertOk();
});

it('can render the view page', function () {
    $user = User::factory()->create();

    livewire(ViewUser::class, [
        'record' => $user->id,
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ]);
});

it('has column', function (string $column) {
    livewire(ListUsers::class)
        ->assertTableColumnExists($column);
})->with(['first_name', 'last_name', 'email', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListUsers::class)
        ->assertCanRenderTableColumn($column);
})->with(['first_name', 'last_name', 'email', 'created_at', 'updated_at']);

it('can sort column', function (string $column) {
    $records = User::factory(5)->create();

    livewire(ListUsers::class)
        ->loadTable()
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column), inOrder: true);
})->with(['last_name']);

it('can search column', function (string $column) {
    $records = User::factory(5)->create();

    $value = $records->first()->{$column};

    livewire(ListUsers::class)
        ->loadTable()
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where($column, $value))
        ->assertCanNotSeeTableRecords($records->where($column, '!=', $value));
})->with(['first_name']);

it('can create a user', function () {
    $user = User::factory()->make();

    livewire(ListUsers::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'password' => $user->password,
        ])
        ->assertNotified();

    assertDatabaseHas(User::class, [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
    ]);
});

it('only shows the staff profile fields for owner and teacher roles', function (string $staffRoleName) {
    $staffRole = Role::findOrCreate($staffRoleName);
    $nonStaffRole = Role::findOrCreate('advisor');

    livewire(ListUsers::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentHidden('staff_bio')
        ->fillForm(['roles' => [$nonStaffRole->id]])
        ->assertSchemaComponentHidden('staff_bio')
        ->fillForm(['roles' => [$staffRole->id]])
        ->assertSchemaComponentVisible('staff_bio');
})->with(['owner', 'teacher']);

it('stores a staff bio for a staff member', function () {
    $teacherRole = Role::findOrCreate('teacher');

    livewire(ListUsers::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Martha',
            'last_name' => 'Graham',
            'email' => 'martha@example.com',
            'password' => 'password',
            'roles' => [$teacherRole->id],
            'staff_bio' => 'Martha teaches modern dance.',
        ])
        ->assertNotified();

    assertDatabaseHas(User::class, [
        'email' => 'martha@example.com',
        'staff_bio' => 'Martha teaches modern dance.',
    ]);
});

it('limits staff bios to 500 characters', function () {
    $teacherRole = Role::findOrCreate('teacher');

    livewire(ListUsers::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Valid',
            'last_name' => 'Biography',
            'email' => 'valid-biography@example.com',
            'password' => 'password',
            'roles' => [$teacherRole->id],
            'staff_bio' => Str::repeat('a', 500),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    livewire(ListUsers::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Invalid',
            'last_name' => 'Biography',
            'email' => 'invalid-biography@example.com',
            'password' => 'password',
            'roles' => [$teacherRole->id],
            'staff_bio' => Str::repeat('a', 501),
        ])
        ->assertHasActionErrors(['staff_bio' => 'max']);

    assertDatabaseHas(User::class, [
        'email' => 'valid-biography@example.com',
        'staff_bio' => Str::repeat('a', 500),
    ]);
    assertDatabaseMissing(User::class, [
        'email' => 'invalid-biography@example.com',
    ]);
});

it('only shows the staff profile on staff member view pages', function () {
    $staffMember = User::factory()->isTeacher()->create();
    $nonStaffMember = User::factory()->create();

    livewire(ViewUser::class, ['record' => $staffMember->id])
        ->assertSchemaComponentVisible('staff_bio', 'infolist');

    livewire(ViewUser::class, ['record' => $nonStaffMember->id])
        ->assertSchemaComponentHidden('staff_bio', 'infolist');
});

it('stores calendar audience tags on role-bearing users', function () {
    $role = Role::findOrCreate('teacher');
    $audienceTag = Tag::findOrCreate('Staff', Calendar::AUDIENCE_TAG_TYPE);

    livewire(ListUsers::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'email' => 'avery@example.com',
            'password' => 'password',
            'roles' => [$role->id],
            'calendar_audience_tag_ids' => [$audienceTag->id],
        ])
        ->assertNotified();

    $user = User::query()->where('email', 'avery@example.com')->firstOrFail();

    expect($user->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())->toBe(['Staff']);
});

it('does not create calendar audience tags from the user form', function () {
    $role = Role::findOrCreate('teacher');

    Tag::query()
        ->where('type', Calendar::AUDIENCE_TAG_TYPE)
        ->delete();

    livewire(ListUsers::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Riley',
            'last_name' => 'North',
            'email' => 'riley@example.com',
            'password' => 'password',
            'roles' => [$role->id],
            'calendar_audience_tag_ids' => [999],
        ])
        ->assertHasActionErrors(['calendar_audience_tag_ids.0']);

    expect(Tag::query()->where('type', Calendar::AUDIENCE_TAG_TYPE)->count())->toBe(0);
});

it('can update a user', function () {
    $user = User::factory()->create();
    $newUserData = User::factory()->make();

    livewire(ViewUser::class, [
        'record' => $user->id,
    ])
        ->callAction(EditAction::class, data: [
            'first_name' => $newUserData->first_name,
            'last_name' => $newUserData->last_name,
            'email' => $newUserData->email,
        ])
        ->assertNotified();

    assertDatabaseHas(User::class, [
        'id' => $user->id,
        'first_name' => $newUserData->first_name,
        'last_name' => $newUserData->last_name,
        'email' => $newUserData->email,
    ]);
});

it('can update a user without changing their email', function () {
    $user = User::factory()->create();

    livewire(ViewUser::class, [
        'record' => $user->id,
    ])
        ->callAction(EditAction::class, data: [
            'first_name' => 'Updated',
            'last_name' => $user->last_name,
            'email' => $user->email,
        ])
        ->assertNotified();

    assertDatabaseHas(User::class, [
        'id' => $user->id,
        'first_name' => 'Updated',
        'email' => $user->email,
    ]);
});

it('can bulk delete users', function () {
    $users = User::factory()->count(5)->create();

    livewire(ListUsers::class)
        ->loadTable()
        ->assertCanSeeTableRecords($users)
        ->selectTableRecords($users)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanNotSeeTableRecords($users);

    $users->each(fn (User $user) => assertDatabaseMissing($user));
});

it('can validate create unique', function (string $column) {
    $record = User::factory()->create();

    livewire(ListUsers::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $record->email,
            'password' => 'password',
        ])
        ->assertHasActionErrors([$column => ['unique']]);
})->with(['email']);

it('validates the create form data', function (array $data, array $errors) {
    $newUserData = User::factory()->make();

    livewire(ListUsers::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => $newUserData->first_name,
            'last_name' => $newUserData->last_name,
            'email' => $newUserData->email,
            'password' => 'password',
            ...$data,
        ])
        ->assertHasActionErrors($errors);
})->with([
    '`first_name` is required' => [['first_name' => null], ['first_name' => 'required']],
    '`first_name` is max 255 characters' => [['first_name' => Str::random(256)], ['first_name' => 'max']],
    '`last_name` is required' => [['last_name' => null], ['last_name' => 'required']],
    '`last_name` is max 255 characters' => [['last_name' => Str::random(256)], ['last_name' => 'max']],
    '`email` is a valid email address' => [['email' => Str::random()], ['email' => 'email']],
    '`email` is required' => [['email' => null], ['email' => 'required']],
    '`email` is max 255 characters' => [['email' => Str::random(256)], ['email' => 'max']],
]);

it('validates the edit form data', function (array $data, array $errors) {
    $user = User::factory()->create();
    $newUserData = User::factory()->make();

    livewire(ViewUser::class, [
        'record' => $user->id,
    ])
        ->callAction(EditAction::class, data: [
            'first_name' => $newUserData->first_name,
            'last_name' => $newUserData->last_name,
            'email' => $newUserData->email,
            ...$data,
        ])
        ->assertHasActionErrors($errors);
})->with([
    '`first_name` is required' => [['first_name' => null], ['first_name' => 'required']],
    '`first_name` is max 255 characters' => [['first_name' => Str::random(256)], ['first_name' => 'max']],
    '`last_name` is required' => [['last_name' => null], ['last_name' => 'required']],
    '`last_name` is max 255 characters' => [['last_name' => Str::random(256)], ['last_name' => 'max']],
    '`email` is a valid email address' => [['email' => Str::random()], ['email' => 'email']],
    '`email` is required' => [['email' => null], ['email' => 'required']],
    '`email` is max 255 characters' => [['email' => Str::random(256)], ['email' => 'max']],
]);
