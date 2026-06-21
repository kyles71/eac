<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

function staffBioLengthMigration(): Migration
{
    return require database_path('migrations/2026_06_21_013234_change_staff_bio_length_on_users_table.php');
}

it('changes the staff bio column between text and varchar', function (): void {
    $migration = staffBioLengthMigration();

    $migration->down();

    expect(Schema::getColumnType('users', 'staff_bio'))->toBe('text');

    $migration->up();

    expect(Schema::getColumnType('users', 'staff_bio'))->toBe('varchar');
});

it('refuses to shorten the staff bio column while overlong values exist', function (): void {
    User::factory()->create([
        'staff_bio' => str_repeat('a', 501),
    ]);

    expect(fn () => staffBioLengthMigration()->up())
        ->toThrow(RuntimeException::class, 'Cannot limit users.staff_bio to 500 characters while longer values exist.');
});
