<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Models\FormUser;
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
                ->modifyQueryUsing(fn (Builder $query): Builder => FormUser::applyPendingConstraint(FormUser::applyFormIsActiveConstraint($query)))
                ->badge(FormUser::applyPendingConstraint(FormUser::applyFormIsActiveConstraint(FormUser::query()))->where('user_id', auth()->id())->count()),
            'completed' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => FormUser::applyCompletedConstraint(FormUser::applyFormIsActiveConstraint($query))->orderByDesc('date_signed'))
                ->badge(FormUser::applyCompletedConstraint(FormUser::applyFormIsActiveConstraint(FormUser::query()))->where('user_id', auth()->id())->count()),
            'expired' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => FormUser::applyFormIsExpiredConstraint($query))
                ->badge(FormUser::applyFormIsExpiredConstraint(FormUser::query())->where('user_id', auth()->id())->count()),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        $userForms = FormUser::query()
            ->where('user_id', auth()->id());

        $hasPendingForms = FormUser::applyPendingConstraint(
            FormUser::applyFormIsActiveConstraint(clone $userForms),
        )->exists();

        if ($hasPendingForms) {
            return 'pending';
        }

        $hasCompletedForms = FormUser::applyCompletedConstraint(
            FormUser::applyFormIsActiveConstraint(clone $userForms),
        )->exists();

        if ($hasCompletedForms) {
            return 'completed';
        }

        if (FormUser::applyFormIsExpiredConstraint(clone $userForms)->exists()) {
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
            ->recordUrl(function (FormUser $record) {
                $action = 'edit';

                if ($record->isCompleted()) {
                    $action = 'view';
                }

                return $this->getResourceUrl($action, ['record' => $record]);
            });
    }
}
