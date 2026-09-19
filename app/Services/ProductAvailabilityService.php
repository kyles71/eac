<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DashboardAudience;
use App\Enums\ProductAvailabilityStatus;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class ProductAvailabilityService
{
    public function __construct(
        private ProductAudienceService $audienceService,
        private CostumeProductAudienceService $costumeAudienceService,
    ) {}

    public function resultFor(Product $product, ?User $user = null, ?CarbonInterface $at = null): ProductAvailabilityStatus
    {
        $at = $this->comparisonTime($at);

        if (! $product->is_active) {
            return ProductAvailabilityStatus::Draft;
        }

        if (! $product->hasValidPricing()) {
            return ProductAvailabilityStatus::InvalidPrice;
        }

        if ($product->available_until !== null && $product->available_until->lte($at)) {
            return ProductAvailabilityStatus::Expired;
        }

        if ($product->available_from !== null && $product->available_from->gt($at)) {
            if ($user === null || ! $this->hasEarlyAccess($product, $user, $at)) {
                return ProductAvailabilityStatus::Scheduled;
            }

            if (! $this->includesAudience($product, $user, $at)) {
                return ProductAvailabilityStatus::EligibilityRequired;
            }

            return ProductAvailabilityStatus::EarlyAccess;
        }

        if ($user !== null && ! $this->includesAudience($product, $user, $at)) {
            return ProductAvailabilityStatus::EligibilityRequired;
        }

        return ProductAvailabilityStatus::Available;
    }

    public function adminStatusFor(Product $product, ?CarbonInterface $at = null): ProductAvailabilityStatus
    {
        $at = $this->comparisonTime($at);

        if (! $product->is_active) {
            return ProductAvailabilityStatus::Draft;
        }

        if (! $product->hasValidPricing()) {
            return ProductAvailabilityStatus::InvalidPrice;
        }

        if ($product->available_until !== null && $product->available_until->lte($at)) {
            return ProductAvailabilityStatus::Expired;
        }

        if ($product->available_from !== null && $product->available_from->gt($at)) {
            return $this->hasActiveEarlyAccessWindow($product, $at)
                ? ProductAvailabilityStatus::EarlyAccess
                : ProductAvailabilityStatus::Scheduled;
        }

        return ProductAvailabilityStatus::Available;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function applyNormallyAvailableToQuery(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at = $this->comparisonTime($at);

        return $query
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $this->validPricingQuery($query);
            })
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('available_from')
                ->orWhere('available_from', '<=', $at))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('available_until')
                ->orWhere('available_until', '>', $at));
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function applyVisibleToQuery(Builder $query, User $user, ?CarbonInterface $at = null): Builder
    {
        $at = $this->comparisonTime($at);
        $audienceValues = $this->audienceValuesFor($user);

        $query
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $this->validPricingQuery($query);
            })
            ->where(function (Builder $query) use ($at, $audienceValues, $user): void {
                $query
                    ->where(function (Builder $query) use ($at): void {
                        $this->applyOpenScheduleToQuery($query, $at);
                    })
                    ->orWhere(function (Builder $query) use ($at, $audienceValues, $user): void {
                        $this->applyEarlyAccessScheduleToQuery($query, $user, $audienceValues, $at);
                    });
            });

        $this->audienceService->applyToQuery($query, $user, $at);

        return $this->costumeAudienceService->applyToQuery($query, $user);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function applyAdminStatusFilter(Builder $query, ProductAvailabilityStatus $status, ?CarbonInterface $at = null): Builder
    {
        $at = $this->comparisonTime($at);

        return match ($status) {
            ProductAvailabilityStatus::Available => $this->applyNormallyAvailableToQuery($query, $at),
            ProductAvailabilityStatus::Draft => $query->where('is_active', false),
            ProductAvailabilityStatus::InvalidPrice => $query
                ->where('is_active', true)
                ->whereNot(function (Builder $query): void {
                    $this->validPricingQuery($query);
                }),
            ProductAvailabilityStatus::Expired => $query
                ->where('is_active', true)
                ->where(function (Builder $query): void {
                    $this->validPricingQuery($query);
                })
                ->whereNotNull('available_until')
                ->where('available_until', '<=', $at),
            ProductAvailabilityStatus::Scheduled => $query
                ->where('is_active', true)
                ->where(function (Builder $query): void {
                    $this->validPricingQuery($query);
                })
                ->whereNotNull('available_from')
                ->where('available_from', '>', $at)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('available_until')
                    ->orWhere('available_until', '>', $at))
                ->where(fn (Builder $query): Builder => $query
                    ->whereDoesntHave('earlyAccessWindows', fn (Builder $query): Builder => $this->activeWindowQuery($query, $at))),
            ProductAvailabilityStatus::EarlyAccess => $query
                ->where('is_active', true)
                ->where(function (Builder $query): void {
                    $this->validPricingQuery($query);
                })
                ->whereNotNull('available_from')
                ->where('available_from', '>', $at)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('available_until')
                    ->orWhere('available_until', '>', $at))
                ->whereHas('earlyAccessWindows', fn (Builder $query): Builder => $this->activeWindowQuery($query, $at)),
            ProductAvailabilityStatus::EligibilityRequired => $query,
        };
    }

    private function validPricingQuery(Builder $query): void
    {
        $query
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('price')
                    ->where('price', '>', 0)
                    ->where(function (Builder $query): void {
                        $this->notCustomerEnteredGiftCardPricingQuery($query);
                    });
            })
            ->orWhere(function (Builder $query): void {
                $this->customerEnteredGiftCardPricingQuery($query);
            });
    }

    private function customerEnteredGiftCardPricingQuery(Builder $query): void
    {
        $query
            ->where('productable_type', GiftCardType::class)
            ->whereHasMorph(
                'productable',
                [GiftCardType::class],
                fn (Builder $query): Builder => $query
                    ->where('allows_custom_amount', true)
                    ->where('minimum_custom_amount', '>=', 100),
            );
    }

    private function notCustomerEnteredGiftCardPricingQuery(Builder $query): void
    {
        $query
            ->whereNull('productable_type')
            ->orWhere('productable_type', '!=', GiftCardType::class)
            ->orWhereDoesntHaveMorph(
                'productable',
                [GiftCardType::class],
                fn (Builder $query): Builder => $query->where('allows_custom_amount', true),
            );
    }

    private function applyOpenScheduleToQuery(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('available_from')
                ->orWhere('available_from', '<=', $at))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('available_until')
                ->orWhere('available_until', '>', $at));
    }

    /**
     * @param  list<string>  $audienceValues
     */
    private function applyEarlyAccessScheduleToQuery(Builder $query, User $user, array $audienceValues, CarbonInterface $at): Builder
    {
        return $query
            ->whereNotNull('available_from')
            ->where('available_from', '>', $at)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('available_until')
                ->orWhere('available_until', '>', $at))
            ->whereHas('earlyAccessWindows', function (Builder $query) use ($audienceValues, $at, $user): Builder {
                $this->activeWindowQuery($query, $at);
                $this->matchingWindowQuery($query, $user, $audienceValues);

                return $query;
            });
    }

    private function activeWindowQuery(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->whereNotNull('available_from')
            ->where('available_from', '<=', $at)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('available_until')
                ->orWhere('available_until', '>', $at));
    }

    /** @param list<string> $audienceValues */
    private function matchingWindowQuery(Builder $query, User $user, array $audienceValues): Builder
    {
        return $query->where(function (Builder $query) use ($audienceValues, $user): void {
            $query->whereHas(
                'users',
                fn (Builder $query): Builder => $query->whereKey($user->getKey()),
            );

            foreach ($audienceValues as $audienceValue) {
                $query->orWhereJsonContains('audiences', $audienceValue);
            }
        });
    }

    private function hasEarlyAccess(Product $product, User $user, CarbonInterface $at): bool
    {
        $audienceValues = $this->audienceValuesFor($user);

        return $product->earlyAccessWindows()
            ->where(function (Builder $query) use ($at): void {
                $this->activeWindowQuery($query, $at);
            })
            ->where(function (Builder $query) use ($audienceValues, $user): void {
                $this->matchingWindowQuery($query, $user, $audienceValues);
            })
            ->exists();
    }

    private function hasActiveEarlyAccessWindow(Product $product, CarbonInterface $at): bool
    {
        return $product->earlyAccessWindows()
            ->where(function (Builder $query) use ($at): void {
                $this->activeWindowQuery($query, $at);
            })
            ->exists();
    }

    private function includesAudience(Product $product, User $user, CarbonInterface $at): bool
    {
        return $this->audienceService->includes($product, $user, $at)
            && $this->costumeAudienceService->includes($product, $user);
    }

    /**
     * @return list<string>
     */
    private function audienceValuesFor(User $user): array
    {
        return collect(app(DashboardAudienceService::class)->audiencesFor($user))
            ->map(fn (DashboardAudience $audience): string => $audience->value)
            ->values()
            ->all();
    }

    private function comparisonTime(?CarbonInterface $at = null): CarbonInterface
    {
        $timezone = (string) config('app.timezone', 'UTC');

        return CarbonImmutable::instance($at ?? now())->setTimezone($timezone);
    }
}
