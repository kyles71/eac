<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Schemas;

use App\Enums\FormTypes;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

final class FormUserForm
{
    public static function configure(Schema $schema, ?FormTypes $form_type = null, bool $withRelationships = true): Schema
    {
        $form_inputs = [];

        if ($form_type) {
            $responseable = Grid::make()
                ->columnSpanFull()
                ->components(
                    $form_type->getFormSchemaClass()::configure($schema, $withRelationships)->getComponents()
                );

            $withRelationships
                ? $responseable->relationship('responseable', relatedModel: $form_type->value)
                : $responseable->statePath('responseable');

            $form_inputs = [
                $responseable,
            ];
        }

        return $schema
            ->columns(2)
            ->components([
                ...$form_inputs,
                TextInput::make('signature')
                    ->required(),
                DatePicker::make('date_signed')
                    ->label('Date')
                    ->date()
                    ->required()
                    ->when(
                        $withRelationships,
                        fn (DatePicker $component): DatePicker => $component
                            ->default(fn (): string => now((string) config('app.display_timezone', config('app.timezone')))->toDateString())
                            ->afterStateHydrated(fn (DatePicker $component, mixed $state) => blank($state)
                                ? $component->state(now((string) config('app.display_timezone', config('app.timezone')))->toDateString())
                                : null),
                    ),
            ]);
    }
}
