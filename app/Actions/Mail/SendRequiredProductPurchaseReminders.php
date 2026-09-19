<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Models\Product;
use App\Models\ProductPurchaseReminderDelivery;
use App\Models\User;
use App\Services\Mail\RequiredProductPurchaseReminderContentService;
use App\Services\ProductPurchaseRequirementService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * @phpstan-import-type PurchaseRequirementRow from ProductPurchaseRequirementService
 *
 * @phpstan-type PurchaseReminder array{product: Product, requirement: PurchaseRequirementRow}
 * @phpstan-type UserPurchaseReminders array{user: User, reminders: list<PurchaseReminder>}
 */
final readonly class SendRequiredProductPurchaseReminders
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private ProductPurchaseRequirementService $requirements,
        private RequiredProductPurchaseReminderContentService $content,
    ) {}

    /** @return array{users_reminded: int, products_marked: int} */
    public function handle(?CarbonInterface $dateTime = null): array
    {
        $at = CarbonImmutable::instance($dateTime ?? now());
        $timezone = (string) config('app.display_timezone', config('app.timezone'));
        $reminderDate = $at->setTimezone($timezone)->toDateString();

        /** @var array<int, UserPurchaseReminders> $remindersByUser */
        $remindersByUser = [];

        Product::query()
            ->where('is_purchase_required', true)
            ->where('is_store_listed', true)
            ->whereNotNull('purchase_reminder_on')
            ->whereDate('purchase_reminder_on', '<=', $reminderDate)
            ->where('available_until', '>', $at)
            ->with('productable')
            ->lazyById()
            ->each(function (Product $product) use ($at, &$remindersByUser): void {
                foreach ($this->requirements->rowsForProduct($product, $at) as $requirement) {
                    $user = $requirement['user'];

                    if ($requirement['remaining'] === 0
                        || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)
                        || ! $product->canBePurchasedBy($user, $at)
                        || $this->wasDelivered($product, $user)) {
                        continue;
                    }

                    $group = $remindersByUser[$user->id] ?? [
                        'user' => $user,
                        'reminders' => [],
                    ];
                    $group['reminders'][] = [
                        'product' => $product,
                        'requirement' => $requirement,
                    ];
                    $remindersByUser[$user->id] = $group;
                }
            });

        $usersReminded = 0;
        $productsMarked = 0;

        foreach ($remindersByUser as $group) {
            $payload = $this->content->for($group['user'], collect($group['reminders']));

            if (! $this->managedEmail->handle(
                recipients: $group['user']->email,
                emailTypeKey: 'required-product-purchase-reminder',
                tokens: $payload['tokens'],
                slots: $payload['slots'],
            )) {
                continue;
            }

            foreach ($group['reminders'] as $reminder) {
                ProductPurchaseReminderDelivery::query()->firstOrCreate([
                    'product_id' => $reminder['product']->id,
                    'user_id' => $group['user']->id,
                    'reminder_on' => $reminder['product']->purchase_reminder_on->toDateString(),
                ], [
                    'sent_at' => now(),
                ]);
                $productsMarked++;
            }

            $usersReminded++;
        }

        return [
            'users_reminded' => $usersReminded,
            'products_marked' => $productsMarked,
        ];
    }

    private function wasDelivered(Product $product, User $user): bool
    {
        return $product->purchaseReminderDeliveries()
            ->where('user_id', $user->id)
            ->whereDate('reminder_on', $product->purchase_reminder_on)
            ->exists();
    }
}
