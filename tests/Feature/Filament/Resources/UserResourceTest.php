<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
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
