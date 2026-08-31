<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;

final class EmailComposer
{
    public static function subject(): TextInput
    {
        return TextInput::make('subject')
            ->label('Subject')
            ->required();
    }

    public static function body(): RichEditor
    {
        return RichEditor::make('body')
            ->label('Body')
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'strike', 'link'],
                ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                ['undo', 'redo'],
            ])
            ->extraAttributes(['class' => 'fi-mail-manager-rich-editor'])
            ->required()
            ->columnSpanFull();
    }
}
