<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Course;
use App\Models\Gear;
use App\Models\GiftCardType;
use Filament\Support\Contracts\HasLabel;
use InvalidArgumentException;

enum ProductType: string implements HasLabel
{
    case Any = 'Any';
    case Course = 'Course';
    case GiftCardType = 'Gift Card';
    case Gear = 'Gear';
    case Standalone = 'Generic Product';

    /**
     * Map a product's productable_type morph class (or null) to the corresponding enum case.
     *
     * @throws InvalidArgumentException if the morph class is unrecognized
     */
    public static function fromProductableType(?string $morphClass): self
    {
        if ($morphClass === null) {
            return self::Standalone;
        }

        return match ($morphClass) {
            Course::class => self::Course,
            GiftCardType::class => self::GiftCardType,
            Gear::class => self::Gear,
            default => throw new InvalidArgumentException("Unrecognized productable type: {$morphClass}"),
        };
    }

    public static function labelForProductableType(?string $morphClass): string
    {
        return self::fromProductableType($morphClass)->getLabel();
    }

    public function getLabel(): string
    {
        return $this->value;
    }

    /**
     * Map this enum case to the productable morph class string.
     * Returns null for Standalone (no morph). Throws for Any (not valid on a product).
     *
     * @throws InvalidArgumentException if called on Any
     */
    public function toProductableClass(): ?string
    {
        return match ($this) {
            self::Any => throw new InvalidArgumentException('ProductType::Any cannot be mapped to a productable class.'),
            self::Course => Course::class,
            self::GiftCardType => GiftCardType::class,
            self::Gear => Gear::class,
            self::Standalone => null,
        };
    }
}
