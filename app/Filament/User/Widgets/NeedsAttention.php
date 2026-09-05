<?php

declare(strict_types=1);

namespace App\Filament\User\Widgets;

use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
use App\Filament\User\Pages\Billing;
use App\Filament\User\Pages\HeldClasses;
use App\Filament\User\Pages\MyEnrollments;
use App\Filament\User\Pages\ProductDetails;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Models\CourseHold;
use App\Models\Enrollment;
use App\Models\FormUser;
use App\Models\Installment;
use App\Models\Product;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;
use App\Services\ProductPurchaseRequirementService;
use Filament\Widgets\Widget;

final class NeedsAttention extends Widget
{
    protected string $view = 'filament.user.widgets.needs-attention';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (new self)->tasks() !== [];
    }

    /**
     * @return list<array{title: string, description: string, url: string, action: string, color: string}>
     */
    public function tasks(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $installments = Installment::query()
            ->whereIn('status', [InstallmentStatus::Overdue, InstallmentStatus::Failed])
            ->whereHas('paymentPlan.order', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', '!=', OrderStatus::Cancelled))
            ->orderBy('due_date')
            ->get()
            ->map(fn (Installment $installment): array => [
                'title' => "{$installment->status->getLabel()} payment",
                'description' => format_money($installment->amount).' due '.$installment->due_date->format('M j, Y'),
                'url' => Billing::getUrl(['tab' => 'payment-plans']),
                'action' => 'Review payment',
                'color' => 'danger',
            ]);

        $forms = FormUser::query()
            ->with(['form', 'student'])
            ->where('user_id', $user->id)
            ->pending()
            ->whereHas('form', fn ($query) => $query
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>', now()))
            ->latest()
            ->get()
            ->map(fn (FormUser $formUser): array => [
                'title' => $formUser->form->name,
                'description' => $formUser->student === null
                    ? 'Complete this required form.'
                    : "Complete for {$formUser->student->first_name} {$formUser->student->last_name}.",
                'url' => FormUserResource::getUrl('edit', ['record' => $formUser]),
                'action' => 'Complete form',
                'color' => 'warning',
            ]);

        $enrollments = Enrollment::query()
            ->with('course')
            ->where('user_id', $user->id)
            ->open()
            ->latest()
            ->get()
            ->filter(fn (Enrollment $enrollment): bool => ! $enrollment->course->hasConcluded())
            ->map(fn (Enrollment $enrollment): array => [
                'title' => $enrollment->course->name,
                'description' => 'Assign this purchased class seat to a student.',
                'url' => MyEnrollments::getUrl(['tab' => 'all']),
                'action' => 'Assign student',
                'color' => 'warning',
            ]);

        $holds = CourseHold::query()
            ->where('user_id', $user->id)
            ->current()
            ->withCount(['seats as available_seats_count' => fn ($query) => $query->available()])
            ->orderBy('expires_at')
            ->get()
            ->map(fn (CourseHold $hold): array => [
                'title' => 'Class seats held for you',
                'description' => $hold->available_seats_count.' '.str('seat')->plural($hold->available_seats_count)
                    .' held until '.$hold->expires_at->format('M j, Y \a\t g:i A'),
                'url' => HeldClasses::getUrl(['hold' => $hold->id]),
                'action' => 'View held classes',
                'color' => 'warning',
            ]);

        $requiredProducts = Product::query()
            ->where('is_purchase_required', true)
            ->where('is_store_listed', true)
            ->whereNotNull('purchase_reminder_on')
            ->whereDate('purchase_reminder_on', '<=', today((string) config('app.display_timezone', config('app.timezone'))))
            ->visibleTo($user)
            ->with('productable')
            ->orderBy('purchase_reminder_on')
            ->get()
            ->map(function (Product $product) use ($user): ?array {
                $requirement = app(ProductPurchaseRequirementService::class)->rowForUser($product, $user);

                if ($requirement === null || $requirement['remaining'] === 0) {
                    return null;
                }

                $quantity = $requirement['remaining'];
                $timezone = (string) config('app.display_timezone', config('app.timezone'));

                return [
                    'title' => "Required purchase: {$product->name}",
                    'description' => $quantity.' '.str('item')->plural($quantity)
                        .' remaining for '.implode(', ', $requirement['targets'])
                        .' · order by '.$product->available_until->timezone($timezone)->format('M j, Y \a\t g:i A'),
                    'url' => ProductDetails::getUrl(['product' => $product], panel: 'user'),
                    'action' => 'View product',
                    'color' => 'warning',
                ];
            })
            ->filter();

        $privateLessons = RecurringPrivateLessonCharge::query()
            ->where('status', RecurringPrivateLessonChargeStatus::Billed)
            ->whereHas('recurringPrivateLesson', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', RecurringPrivateLessonStatus::Active))
            ->whereHas('event', fn ($query) => $query
                ->whereNull('cancelled_at')
                ->where('start_time', '>', now()->addDay()))
            ->with(['event', 'recurringPrivateLesson.student'])
            ->orderBy(
                \App\Models\Event::query()
                    ->select('start_time')
                    ->whereColumn('events.id', 'recurring_private_lesson_charges.event_id'),
            )
            ->get()
            ->map(fn (RecurringPrivateLessonCharge $charge): array => [
                'title' => 'Recurring private lesson payment due',
                'description' => $charge->recurringPrivateLesson->student->displayName()
                    .' · '.format_money($charge->amount)
                    .' · '.$charge->event->start_time
                        ->timezone((string) config('app.display_timezone', config('app.timezone')))
                        ->format('M j, Y \a\t g:i A'),
                'url' => Billing::getUrl(['tab' => 'private-lessons']),
                'action' => 'Review recurring private lessons',
                'color' => 'warning',
            ]);

        return $installments
            ->concat($forms)
            ->concat($enrollments)
            ->concat($holds)
            ->concat($requiredProducts)
            ->concat($privateLessons)
            ->values()
            ->all();
    }
}
