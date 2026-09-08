<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Models\FormAssignment;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ListFormUsers extends ListRecords
{
    protected static string $resource = FormUserResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make()
                ->modifyQueryUsing(function (Builder $query): Builder {
                    FormAssignment::applyPendingConstraint($query);
                    FormAssignment::applyActiveConstraint($query);

                    return $query;
                })
                ->badge($this->badgeCount('pending')),
            'completed' => Tab::make()
                ->modifyQueryUsing(function (Builder $query): Builder {
                    FormAssignment::applyCompletedConstraint($query);
                    FormAssignment::applyActiveConstraint($query);

                    return $query->latest('updated_at');
                })
                ->badge($this->badgeCount('completed')),
            'expired' => Tab::make()
                ->modifyQueryUsing(function (Builder $query): Builder {
                    FormAssignment::applyExpiredConstraint($query);

                    return $query;
                })
                ->badge($this->badgeCount('expired')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        $query = $this->baseBadgeQuery();

        $pendingForms = clone $query;
        FormAssignment::applyActiveConstraint($pendingForms);
        FormAssignment::applyPendingConstraint($pendingForms);
        $hasPendingForms = $pendingForms->exists();

        if ($hasPendingForms) {
            return 'pending';
        }

        $completedForms = clone $query;
        FormAssignment::applyActiveConstraint($completedForms);
        FormAssignment::applyCompletedConstraint($completedForms);
        $hasCompletedForms = $completedForms->exists();

        if ($hasCompletedForms) {
            return 'completed';
        }

        $expiredForms = clone $query;
        FormAssignment::applyExpiredConstraint($expiredForms);
        $hasExpiredForms = $expiredForms->exists();

        if ($hasExpiredForms) {
            return 'expired';
        }

        return 'pending';
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->emptyStateHeading(fn (): string => match ($this->activeTab) {
                'completed' => 'No completed forms',
                'expired' => 'No expired forms',
                default => 'No forms to complete',
            })
            ->emptyStateDescription(fn (): string => match ($this->activeTab) {
                'completed' => 'Completed forms will appear here.',
                'expired' => 'Expired forms will appear here.',
                default => 'Forms that need your attention will appear here.',
            })
            ->recordUrl(function (FormAssignment $record) {
                $action = 'edit';

                if ($record->isCompleted()) {
                    $action = 'view';
                }

                return $this->getResourceUrl($action, ['record' => $record]);
            });
    }

    private function badgeCount(string $scope): int
    {
        $query = $this->baseBadgeQuery();

        if ($scope === 'pending') {
            FormAssignment::applyPendingConstraint($query);
            FormAssignment::applyActiveConstraint($query);

            return $query->count();
        }

        if ($scope === 'completed') {
            FormAssignment::applyCompletedConstraint($query);
            FormAssignment::applyActiveConstraint($query);

            return $query->count();
        }

        if ($scope === 'expired') {
            FormAssignment::applyExpiredConstraint($query);

            return $query->count();
        }

        return 0;
    }

    /** @return Builder<FormAssignment> */
    private function baseBadgeQuery(): Builder
    {
        $query = FormAssignment::query();
        $user = auth()->user();

        return $user instanceof User
            ? $query->accessibleBy($user)
            : $query->whereRaw('1 = 0');
    }
}
