<?php

declare(strict_types=1);

use App\Models\AcademicTerm;
use Carbon\CarbonImmutable;

it('synchronizes academic terms from the console command', function (): void {
    CarbonImmutable::setTestNow('2026-08-15 12:00:00');
    AcademicTerm::query()->delete();

    $this->artisan('academic-terms:sync')
        ->expectsOutputToContain('6 changed')
        ->assertSuccessful();

    expect(AcademicTerm::query()->count())->toBe(6);
});
