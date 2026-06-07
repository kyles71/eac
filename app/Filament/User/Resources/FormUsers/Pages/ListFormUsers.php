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
                ->modifyQueryUsing(fn (Builder $query) => $query->formIsActive()->pending())
                ->badge(FormUser::query()->formIsActive()->pending()->where('user_id', auth()->id())->count()),
            'completed' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->formIsActive()->completed()->orderByDesc('date_signed'))
                ->badge(FormUser::query()->formIsActive()->completed()->where('user_id', auth()->id())->count()),
            'expired' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->formIsExpired())
                ->badge(FormUser::query()->formIsExpired()->where('user_id', auth()->id())->count()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        $has_pending = FormUser::query()
            ->where('user_id', auth()->id())
            ->pending()
            ->exists();

        return $has_pending ? 'pending' : 'all';
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->recordUrl(function (FormUser $record) {
                $action = 'edit';

                if ($record->isCompleted()) {
                    $action = 'view';
                }

                return $this->getResourceUrl($action, ['record' => $record]);
            });
    }
}
