<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
use App\Filament\User\Pages\Billing;
use App\Filament\User\Pages\HeldClasses;
use App\Filament\User\Pages\MyEnrollments;
use App\Filament\User\Pages\PayPaymentPlan;
use App\Filament\User\Pages\ProductDetails;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\FormAssignment;
use App\Models\Installment;
use App\Models\Product;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\Student;
use App\Models\User;
use App\Services\ProductPurchaseRequirementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class UserAttention
{
    public const string UPDATED_EVENT = 'user-attention-updated';

    /**
     * @return Collection<int, FormAssignment>
     */
    public function pendingForms(User $user): Collection
    {
        return FormAssignment::query()
            ->with(['form', 'subject'])
            ->accessibleBy($user)
            ->pending()
            ->formIsActive()
            ->get();
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return Collection<int, FormAssignment>
     */
    public function pendingFormsForStudents(User $user, array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return $this->pendingForms($user)
            ->filter(fn (FormAssignment $assignment): bool => $assignment->subject_type === (new Student())->getMorphClass()
                && in_array($assignment->subject_id, $studentIds, true))
            ->values();
    }

    /**
     * @return list<array{group: string, title: string, description: string, url: string, action: string, color: string}>
     */
    public function tasks(User $user): array
    {
        $installments = Installment::query()
            ->whereIn('status', [InstallmentStatus::Overdue, InstallmentStatus::Failed])
            ->whereHas('paymentPlan.order', fn (Builder $query): Builder => $query
                ->where('user_id', $user->id)
                ->where('status', '!=', OrderStatus::Cancelled))
            ->orderBy('due_date')
            ->get()
            ->map(fn (Installment $installment): array => [
                'group' => 'payments',
                'title' => "{$installment->status->getLabel()} payment",
                'description' => format_money($installment->amount).' due '.$installment->due_date->format('M j, Y'),
                'url' => PayPaymentPlan::getUrl(['paymentPlan' => $installment->payment_plan_id]),
                'action' => 'Pay now',
                'color' => 'danger',
            ]);

        $forms = $this->pendingForms($user)
            ->sortByDesc('created_at')
            ->map(fn (FormAssignment $assignment): array => [
                'group' => 'forms',
                'title' => $assignment->form->name,
                'description' => $assignment->subject === null
                    ? 'Complete this required form.'
                    : "Complete for {$this->modelLabel($assignment->subject)}.",
                'url' => FormUserResource::getUrl('edit', ['record' => $assignment]),
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
                'group' => 'class_assignments',
                'title' => $enrollment->course->name,
                'description' => 'Assign this purchased class seat to a student.',
                'url' => MyEnrollments::getUrl(['tab' => 'all']),
                'action' => 'Assign student',
                'color' => 'warning',
            ]);

        $holds = CourseHold::query()
            ->where('user_id', $user->id)
            ->current()
            ->withCount(['seats as available_seats_count' => fn (Builder $query): Builder => CourseHoldSeat::applyAvailableConstraint($query)])
            ->orderBy('expires_at')
            ->get()
            ->map(fn (CourseHold $hold): array => [
                'group' => 'held_classes',
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
                    'group' => 'required_products',
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
            ->whereHas('recurringPrivateLesson', fn (Builder $query): Builder => $query
                ->where('user_id', $user->id)
                ->where('status', RecurringPrivateLessonStatus::Active))
            ->whereHas('event', fn (Builder $query): Builder => $query
                ->whereNull('cancelled_at')
                ->where('start_time', '>', now()->addDay()))
            ->with(['event', 'recurringPrivateLesson.student'])
            ->orderBy(
                Event::query()
                    ->select('start_time')
                    ->whereColumn('events.id', 'recurring_private_lesson_charges.event_id'),
            )
            ->get()
            ->map(fn (RecurringPrivateLessonCharge $charge): array => [
                'group' => 'private_lessons',
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

    private function modelLabel(?Model $model): string
    {
        if ($model === null) {
            return '-';
        }

        if (method_exists($model, 'displayName')) {
            return (string) $model->displayName();
        }

        if (filled($model->getAttribute('fullName'))) {
            return (string) $model->getAttribute('fullName');
        }

        if (filled($model->getAttribute('name'))) {
            return (string) $model->getAttribute('name');
        }

        return class_basename($model).' #'.$model->getKey();
    }
}
