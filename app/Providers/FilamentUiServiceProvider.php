<?php

declare(strict_types=1);

namespace App\Providers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

final class FilamentUiServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // When a field has multiple words like "due_date", the label changes from "Due date" to "Due Date".
        Field::configureUsing(function (Field $field) {
            $field->label(function (Component $component) {
                return str($component->getName())
                    ->afterLast('.')
                    ->kebab()
                    ->replace(['-', '_'], ' ')
                    ->ucwords();
            });

            $field->validationAttribute(function (Component $component) {
                return $component->getLabel();
            });

            return $field;
        });

        // make selects searchable and preloaded by default
        Select::configureUsing(function (Select $field) {
            return $field
                ->searchable()
                ->preload();
        });

        // add sensible min and max so you don't end up with dates like 01/01/0000 or 01/01/3000
        DatePicker::configureUsing(function (DatePicker $datePicker) {
            return $datePicker
                ->minDate(Carbon::createFromDate(1500, 1, 1))
                ->maxDate(now()->addYears(30));
        });

        // default to not showing seconds in the datetime picker, adjust as needed
        DateTimePicker::configureUsing(function (DateTimePicker $picker) {
            $picker->seconds(false);
        });

        // US based phone input, adjust for different countries
        TextInput::macro('phone', function () {
            return $this->mask('(999) 999-9999')
                ->prefixIcon('heroicon-o-phone')
                ->tel()
                ->minLength(14)
                ->maxLength(14)
                ->validationMessages([
                    'min' => 'Please enter a valid phone number including area code.',
                ]);
        });

        // if an action is a modal, do not close by clicking away and default to slideover
        Action::configureUsing(function (Action $action) {
            $action
                ->closeModalByClickingAway(false)
                ->slideOver();
        });

        // capitalize the model name in a create action label
        CreateAction::configureUsing(function (CreateAction $action) {
            $action
                ->label(fn (): string => __('filament-actions::create.single.label', ['label' => ucwords($action->getModelLabel())]));
        });

        // various table presets
        Table::configureUsing(function (Table $table) {
            return $table
                ->striped()
                ->deferLoading()
                ->reorderableColumns()
                // ->columnManagerColumns(2)
                ->defaultDateTimeDisplayFormat('F j, Y, g:ia')
                ->columnManagerTriggerAction(fn (Action $action) => $action->button()->label('Columns'))
                ->filtersTriggerAction(fn (Action $action) => $action->button()->label('Filters')->slideOver()->closeModalByClickingAway(true))
                ->filtersFormWidth(Width::Small)
                ->paginationPageOptions([10, 25, 50]);
        });

        // allow any column to be toggled
        Column::configureUsing(function (Column $column) {
            return $column->toggleable();
        });

        // default each text column to be sortable and searchable
        TextColumn::configureUsing(function (TextColumn $textColumn) {
            return $textColumn
                ->searchable() // BE CAREFUL, you may end up with 500 errors
                ->sortable(); // BE CAREFUL, you may end up with 500 errors
        });

        // make notifications last 10 seconds by default
        Notification::configureUsing(function (Notification $notification) {
            return $notification->duration(10000);
        });

        // use your preferred date displays
        Schema::configureUsing(function (Schema $schema) {
            return $schema
                ->defaultDateDisplayFormat('m/d/Y')
                ->defaultDateTimeDisplayFormat('h:i A')
                ->defaultTimeDisplayFormat('m/d/Y h:i A');
        });
    }
}
