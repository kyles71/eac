<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function () {
    /* The TestCase setup generates a user before each test, so we need to clear the table to make sure we have a clean slate. */
    User::truncate();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin);
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
})->with(['full_name', 'email', 'roles.name', 'available_store_credit', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListUsers::class)
        ->loadTable()
        ->assertCanRenderTableColumn($column);
})->with(['full_name', 'email', 'roles.name', 'created_at']);

it('can sort by the directory name column', function () {
    User::factory(5)->create();

    livewire(ListUsers::class)
        ->loadTable()
        ->sortTable('full_name')
        ->assertOk()
        ->sortTable('full_name', 'desc')
        ->assertOk();
});

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
    $staffMember = User::factory()->create();
    $staffMember->assignRole(Role::findOrCreate($staffRoleName));
    $nonStaffMember = User::factory()->create();
    $nonStaffMember->assignRole(Role::findOrCreate('advisor'));

    livewire(ViewUser::class, ['record' => $nonStaffMember->id])
        ->mountAction(EditAction::class)
        ->assertSchemaComponentHidden('staff_bio');

    livewire(ViewUser::class, ['record' => $staffMember->id])
        ->mountAction(EditAction::class)
        ->assertSchemaComponentVisible('staff_bio');
})->with(['owner', 'teacher']);

it('stores a staff bio for a staff member', function () {
    $teacher = User::factory()->create();
    $teacher->assignRole('teacher');

    livewire(ViewUser::class, ['record' => $teacher->id])
        ->callAction(EditAction::class, data: [
            'first_name' => $teacher->first_name,
            'last_name' => $teacher->last_name,
            'email' => $teacher->email,
            'staff_bio' => 'Martha teaches modern dance.',
        ])
        ->assertNotified();

    assertDatabaseHas(User::class, [
        'id' => $teacher->id,
        'staff_bio' => 'Martha teaches modern dance.',
    ]);
});

it('limits staff bios to 500 characters', function () {
    $teacher = User::factory()->create();
    $teacher->assignRole('teacher');

    livewire(ViewUser::class, ['record' => $teacher->id])
        ->callAction(EditAction::class, data: [
            'first_name' => $teacher->first_name,
            'last_name' => $teacher->last_name,
            'email' => $teacher->email,
            'staff_bio' => Str::repeat('a', 500),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    livewire(ViewUser::class, ['record' => $teacher->id])
        ->callAction(EditAction::class, data: [
            'first_name' => $teacher->first_name,
            'last_name' => $teacher->last_name,
            'email' => $teacher->email,
            'staff_bio' => Str::repeat('a', 501),
        ])
        ->assertHasActionErrors(['staff_bio' => 'max']);

    assertDatabaseHas(User::class, [
        'id' => $teacher->id,
        'staff_bio' => Str::repeat('a', 500),
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
