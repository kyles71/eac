<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class ApplicationDateTime
{
    public static function fromDisplayInput(DateTimeInterface|string $value): CarbonImmutable
    {
        return self::parse($value, self::displayTimezone())
            ->timezone(self::storageTimezone());
    }

    public static function tryFromDisplayInput(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            return null;
        }

        try {
            return self::fromDisplayInput($value);
        } catch (Throwable) {
            return null;
        }
    }

    public static function fromStorage(DateTimeInterface|string $value): CarbonImmutable
    {
        return self::parse($value, self::storageTimezone())
            ->timezone(self::storageTimezone());
    }

    public static function tryFromStorage(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            return null;
        }

        try {
            return self::fromStorage($value);
        } catch (Throwable) {
            return null;
        }
    }

    public static function forDisplay(DateTimeInterface|string $value): CarbonImmutable
    {
        return self::parse($value, self::storageTimezone())
            ->timezone(self::displayTimezone());
    }

    public static function endOfDisplayDay(DateTimeInterface|string $value): CarbonImmutable
    {
        return self::parse($value, self::displayTimezone())
            ->timezone(self::displayTimezone())
            ->endOfDay()
            ->timezone(self::storageTimezone());
    }

    public static function displayTimezone(): string
    {
        $timezone = config('app.display_timezone', self::storageTimezone());

        return is_string($timezone) ? $timezone : self::storageTimezone();
    }

    public static function storageTimezone(): string
    {
        $timezone = config('app.timezone', 'UTC');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    private static function parse(DateTimeInterface|string $value, string $sourceTimezone): CarbonImmutable
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, $sourceTimezone);
    }
}
