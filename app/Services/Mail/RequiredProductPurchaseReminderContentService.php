<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductPurchaseRequirementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** @phpstan-import-type PurchaseRequirementRow from ProductPurchaseRequirementService */
final readonly class RequiredProductPurchaseReminderContentService
{
    /**
     * @param  Collection<int, array{product: Product, requirement: PurchaseRequirementRow}>  $reminders
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(User $user, Collection $reminders): array
    {
        $count = $reminders->count();

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $user->first_name,
                'user.full_name' => $user->fullName,
                'user.email' => $user->email,
                'required_products.count' => (string) $count,
                'required_products.label' => Str::plural('product', $count),
            ],
            'slots' => [
                'required-products' => view('mail.required-product-purchase-reminder-details', [
                    'reminders' => $reminders,
                ])->render(),
            ],
        ];
    }
}
