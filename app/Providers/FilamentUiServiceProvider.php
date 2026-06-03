<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Student;
use App\Models\User;
use App\Support\Filament\SelectSearch;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
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
        $displayTimezone = config('app.display_timezone');

        FilamentTimezone::set(is_string($displayTimezone) ? $displayTimezone : 'UTC');

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

        // make selects searchable by default, but only preload explicitly where the option list is small
        Select::configureUsing(function (Select $field) {
            return $field->searchable();
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

        TextInput::macro('moneyCents', function (float|int $minValue = 0): TextInput {
            return $this
                ->numeric()
                ->prefix('$')
                ->minValue($minValue)
                ->formatStateUsing(fn (mixed $state): ?string => is_numeric($state) ? number_format(((int) $state) / 100, 2, '.', '') : null)
                ->dehydrateStateUsing(fn (mixed $state): ?int => filled($state) ? (int) round(((float) str_replace(',', '', (string) $state)) * 100) : null);
        });

        TextColumn::macro('moneyCents', function (?string $placeholder = null): TextColumn {
            $column = $this->formatStateUsing(fn (mixed $state): ?string => is_numeric($state) ? format_money((int) $state) : null);

            if ($placeholder !== null) {
                $column->placeholder($placeholder);
            }

            return $column;
        });

        TextEntry::macro('moneyCents', function (?string $placeholder = null): TextEntry {
            $entry = $this->formatStateUsing(fn (mixed $state): ?string => is_numeric($state) ? format_money((int) $state) : null);

            if ($placeholder !== null) {
                $entry->placeholder($placeholder);
            }

            return $entry;
        });

        Select::macro('searchableRelationship', function (
            string $name,
            array $searchColumns,
            Closure $labelFromRecord,
            ?Closure $modifyQueryUsing = null,
            array $orderBy = [],
            string $titleAttribute = 'id',
        ): Select {
            return SelectSearch::relationship(
                select: $this,
                name: $name,
                searchColumns: $searchColumns,
                labelFromRecord: $labelFromRecord,
                modifyQueryUsing: $modifyQueryUsing,
                orderBy: $orderBy,
                titleAttribute: $titleAttribute,
            );
        });

        Select::macro('userRelationship', function (
            string $name = 'user',
            ?Closure $modifyQueryUsing = null,
        ): Select {
            return $this->searchableRelationship(
                name: $name,
                searchColumns: ['first_name', 'last_name', 'email'],
                labelFromRecord: fn (User $user): string => filled($user->email)
                    ? "{$user->fullName} ({$user->email})"
                    : $user->fullName,
                modifyQueryUsing: $modifyQueryUsing,
                orderBy: ['first_name', 'last_name'],
            );
        });

        Select::macro('studentRelationship', function (
            string $name = 'student',
            ?Closure $modifyQueryUsing = null,
        ): Select {
            return $this->searchableRelationship(
                name: $name,
                searchColumns: ['first_name', 'last_name'],
                labelFromRecord: fn (Student $student): string => $student->fullName,
                modifyQueryUsing: $modifyQueryUsing,
                orderBy: ['first_name', 'last_name'],
            );
        });

        // resource forms in this app often need room for media, repeaters, and grouped sections
        EditAction::configureUsing(function (EditAction $action) {
            $action
                ->slideOver();
        });

        // capitalize the model name in a create action label
        CreateAction::configureUsing(function (CreateAction $action) {
            $action
                ->slideOver()
                ->label(fn (): string => __('filament-actions::create.single.label', ['label' => ucwords($action->getModelLabel())]));
        });

        // various table presets
        Table::configureUsing(function (Table $table) {
            return $table
                ->striped()
                ->deferLoading()
                ->reorderableColumns()
                // ->columnManagerColumns(2)
                ->defaultDateTimeDisplayFormat('M j, Y g:i A')
                ->columnManagerTriggerAction(fn (Action $action) => $action->button()->label('Columns'))
                ->filtersTriggerAction(fn (Action $action) => $action->button()->label('Filters')->slideOver()->closeModalByClickingAway(true))
                ->filtersFormWidth(Width::Small)
                ->paginationPageOptions([10, 25, 50]);
        });

        // make notifications last 10 seconds by default
        Notification::configureUsing(function (Notification $notification) {
            return $notification->duration(10000);
        });

        // use your preferred date displays
        Schema::configureUsing(function (Schema $schema) {
            return $schema
                ->defaultDateDisplayFormat('M j, Y')
                ->defaultDateTimeDisplayFormat('M j, Y g:i A')
                ->defaultTimeDisplayFormat('g:i A');
        });
    }
}
