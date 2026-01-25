<?php

declare(strict_types=1);

namespace App\Providers;

use BezhanSalleh\PanelSwitch\PanelSwitch;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureTable();

        PanelSwitch::configureUsing(function (PanelSwitch $panelSwitch) {
            $panelSwitch->simple();
        });
    }

    private function configureTable(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table->striped()
                ->deferLoading();
        });

        CreateAction::configureUsing(function (Action $action): void {
            $action->slideOver();
        });

        EditAction::configureUsing(function (Action $action): void {
            $action->slideOver();
        });
    }
}
