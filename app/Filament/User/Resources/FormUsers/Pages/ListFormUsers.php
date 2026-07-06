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
        FormAssignment::applyPendingConstraint($query);
        FormAssignment::applyActiveConstraint($query);

        return $query->exists() ? 'pending' : 'all';
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
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
            ? $query
                ->where('respondent_type', $user->getMorphClass())
                ->where('respondent_id', $user->getKey())
            : $query->whereRaw('1 = 0');
    }
}
