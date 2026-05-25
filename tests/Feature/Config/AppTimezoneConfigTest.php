<?php

declare(strict_types=1);

use App\Models\FormUser;
use Carbon\CarbonInterface;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

it('stores datetimes in UTC and displays Filament datetimes in the configured local timezone', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.display_timezone'))->toBeString()
        ->and(FilamentTimezone::get())->toBe(config('app.display_timezone'));
});

it('uses the same default datetime display format for tables and infolists', function (): void {
    $tableLivewire = Mockery::mock(HasTable::class);

    expect(Schema::make()->getDefaultDateTimeDisplayFormat())->toBe('M j, Y g:i A')
        ->and(Table::make($tableLivewire)->getDefaultDateTimeDisplayFormat())->toBe('M j, Y g:i A');
});

it('casts form signature dates as dates instead of datetimes', function (): void {
    $formUser = FormUser::factory()->create([
        'date_signed' => '2026-05-24',
    ]);

    expect($formUser->date_signed)
        ->toBeInstanceOf(CarbonInterface::class)
        ->and($formUser->date_signed->toDateString())->toBe('2026-05-24')
        ->and($formUser->date_signed->format('H:i:s'))->toBe('00:00:00');
});
