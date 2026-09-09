<?php

declare(strict_types=1);

use App\Support\ApplicationDateTime;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

beforeEach(function (): void {
    config([
        'app.timezone' => 'UTC',
        'app.display_timezone' => 'America/Detroit',
    ]);
});

it('normalizes display input and stored values to the storage timezone', function (): void {
    $displayInput = ApplicationDateTime::fromDisplayInput('2027-08-31 10:05:00');
    $storedValue = ApplicationDateTime::fromStorage('2027-08-31 14:05:00');

    expect($displayInput->toIso8601String())->toBe('2027-08-31T14:05:00+00:00')
        ->and($storedValue->toIso8601String())->toBe('2027-08-31T14:05:00+00:00')
        ->and($displayInput->timezoneName)->toBe('UTC')
        ->and($storedValue->timezoneName)->toBe('UTC');
});

it('preserves explicit offsets and datetime instants', function (): void {
    $offsetValue = ApplicationDateTime::fromDisplayInput('2027-08-31T10:05:00-04:00');
    $dateTime = ApplicationDateTime::fromDisplayInput(
        CarbonImmutable::parse('2027-08-31 10:05:00', 'America/Detroit'),
    );

    expect($offsetValue->toIso8601String())->toBe('2027-08-31T14:05:00+00:00')
        ->and($dateTime->toIso8601String())->toBe('2027-08-31T14:05:00+00:00');
});

it('converts stored instants for display across seasonal offsets', function (): void {
    $summer = ApplicationDateTime::forDisplay('2027-08-31 14:05:00');
    $winter = ApplicationDateTime::forDisplay('2027-01-31 15:05:00');

    expect($summer->format('Y-m-d H:i T'))->toBe('2027-08-31 10:05 EDT')
        ->and($winter->format('Y-m-d H:i T'))->toBe('2027-01-31 10:05 EST');
});

it('converts the end of a display date to the corresponding storage instant', function (): void {
    $endOfDay = ApplicationDateTime::endOfDisplayDay('2027-08-31');

    expect($endOfDay->toIso8601String())->toBe('2027-09-01T03:59:59+00:00')
        ->and($endOfDay->micro)->toBe(999999);
});

it('rejects invalid datetime strings', function (): void {
    expect(fn (): CarbonImmutable => ApplicationDateTime::fromDisplayInput('not-a-date'))
        ->toThrow(InvalidFormatException::class)
        ->and(ApplicationDateTime::tryFromDisplayInput('not-a-date'))->toBeNull()
        ->and(ApplicationDateTime::tryFromStorage([]))->toBeNull();
});
