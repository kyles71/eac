<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Schemas;

use App\Forms\FormSchemaCompiler;
use App\Models\FormAssignment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FormUserForm
{
    public static function configure(Schema $schema, FormAssignment $assignment): Schema
    {
        $assignment->loadMissing(['form', 'version', 'subject']);
        $components = app(FormSchemaCompiler::class)->components($assignment->version, $assignment);

        return $schema
            ->columns(2)
            ->components([
                Section::make($assignment->form->name)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema($components),
                Section::make('Signature')
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible($assignment->version->requires_signature)
                    ->schema([
                        TextInput::make('signature')
                            ->required($assignment->version->requires_signature),
                        DatePicker::make('date_signed')
                            ->label('Date Signed')
                            ->default(fn (): string => now((string) config('app.display_timezone', config('app.timezone')))->toDateString())
                            ->required($assignment->version->requires_signature),
                    ]),
            ]);
    }
}
