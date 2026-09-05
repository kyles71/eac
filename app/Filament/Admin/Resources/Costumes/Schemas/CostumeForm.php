<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Schemas;

use App\Models\AcademicTerm;
use App\Models\Costume;
use App\Models\Course;
use App\Support\MediaDisks;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class CostumeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Costume')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('course_academic_term_id')
                            ->label('Course Academic Term')
                            ->options(fn (): array => AcademicTerm::query()
                                ->orderByDesc('starts_on')
                                ->get()
                                ->mapWithKeys(fn (AcademicTerm $term): array => [$term->id => $term->display_name])
                                ->all())
                            ->default(fn (?Costume $record): ?int => $record?->course->academic_term_id
                                ?? AcademicTerm::query()->current()->value('id'))
                            ->placeholder('All Academic Terms')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->columnStart(1)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('course_id', null))
                            ->dehydrated(false),
                        Select::make('course_id')
                            ->label('Course')
                            ->relationship(
                                name: 'course',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                    ->with('academicTerm')
                                    ->when(
                                        filled($get('course_academic_term_id')),
                                        fn (Builder $query): Builder => $query->where(
                                            'academic_term_id',
                                            $get('course_academic_term_id'),
                                        ),
                                    )
                                    ->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Course $record): string => "{$record->name} ({$record->academicTerm->display_name})")
                            ->searchable(['name'])
                            ->preload()
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('vendor')
                            ->maxLength(255),
                        TextInput::make('vendor_number')
                            ->label('Vendor Number')
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->rows(5)
                            ->columnSpanFull(),
                    ]),
                Section::make('Media')
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::public())
                            ->visibility('public')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
