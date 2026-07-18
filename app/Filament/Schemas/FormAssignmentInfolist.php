<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Models\FormAssignment;
use App\Models\FormResponse;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Kyle\FilamentFormBuilder\Support\FormSchemaCompiler;

final class FormAssignmentInfolist
{
    public static function configure(Schema $schema, FormAssignment $assignment): Schema
    {
        $assignment->loadMissing(['latestSubmittedResponse.version', 'version', 'subject']);
        $response = $assignment->latestSubmittedResponse;
        $responseVersion = $response instanceof FormResponse
            ? $response->version
            : $assignment->version;

        $components = app(FormSchemaCompiler::class)->readOnlyComponents(
            $responseVersion,
            $assignment,
            'latestSubmittedResponse.response_state',
        );

        if ($response instanceof FormResponse && $responseVersion->requires_signature) {
            $components[] = Section::make('Signature')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('latestSubmittedResponse.signature')->label('Signature')->placeholder('-'),
                    TextEntry::make('latestSubmittedResponse.date_signed')->label('Date Signed')->date()->placeholder('-'),
                ]);
        }

        return $schema
            ->columns(2)
            ->components($components);
    }
}
