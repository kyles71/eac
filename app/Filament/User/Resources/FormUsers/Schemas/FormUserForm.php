<?php

namespace App\Filament\User\Resources\FormUsers\Schemas;

use App\Enums\FormTypes;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class FormUserForm
{
    public static function configure(Schema $schema, ?FormTypes $form_type = null): Schema
    {
        $form_inputs = [];

        if ($form_type) {
            $form_inputs = [
                Grid::make()
                    ->relationship('responseable', relatedModel: $form_type->value)
                    ->columnSpanFull()
                    ->components(
                        $form_type->getFormSchemaClass()::configure($schema)->getComponents() // maybe use clone instead
                    )
            ];
        }

        return $schema
            ->components([
                ...$form_inputs,
                TextInput::make('signature'),
                DatePicker::make('date_signed')
                    ->label('Date')
                    ->date(),
            ]);
    }
}
