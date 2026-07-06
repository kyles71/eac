<?php

declare(strict_types=1);

namespace App\Filament\User\Widgets;

use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Filament\User\Pages\Billing;
use App\Filament\User\Pages\MyEnrollments;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Models\Enrollment;
use App\Models\FormAssignment;
use App\Models\Installment;
use App\Models\User;
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

        $forms = FormAssignment::query()
            ->with(['form', 'subject'])
            ->forRespondent($user)
            ->pending()
            ->formIsActive()
            ->latest()
            ->get()
            ->map(fn (FormAssignment $assignment): array => [
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
                'title' => $enrollment->course->name,
                'description' => 'Assign this purchased class seat to a student.',
                'url' => MyEnrollments::getUrl(['tab' => 'all']),
                'action' => 'Assign student',
                'color' => 'warning',
            ]);

        return $installments
            ->concat($forms)
            ->concat($enrollments)
            ->values()
            ->all();
    }

    private function modelLabel(?\Illuminate\Database\Eloquent\Model $model): string
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
