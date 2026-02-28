<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use Closure;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SendEmail
{
    /**
     * @param  array<int, string>|Closure(): array<int, string>  $to
     */
    public static function configure(Schema $schema, array|Closure $to = []): Schema
    {
        $defaultTo = $to instanceof Closure ? $to() : $to;

        return $schema
            ->components([
                TagsInput::make('to')
                    ->label('To')
                    ->default($defaultTo)
                    ->nestedRecursiveRules(['email'])
                    ->placeholder('Add email address')
                    ->required(),
                TextInput::make('subject')
                    ->label('Subject')
                    ->required(),
                Textarea::make('body')
                    ->label('Body')
                    ->rows(5)
                    ->required(),
            ]);
    }
}
