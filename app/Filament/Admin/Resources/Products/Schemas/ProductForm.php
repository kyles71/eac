<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\ProductType;
use App\Models\Costume;
use App\Models\Course;
use App\Models\GiftCardType;
use App\Support\MediaDisks;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Store Details')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Store Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('price')
                            ->label('Price')
                            ->moneyCents()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Store Description')
                            ->columnSpanFull(),
                    ]),
                Section::make('Linked Item')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('productable_type')
                            ->label('Product Type')
                            ->options([
                                Course::class => 'Course',
                                GiftCardType::class => 'Gift Card',
                                Costume::class => 'Costume',
                            ])
                            ->placeholder(ProductType::Standalone->getLabel())
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('productable_id', null);
                                $set('include_productable_images', false);
                            }),
                        Select::make('productable_id')
                            ->label(fn (Get $get): string => match ($get('productable_type')) {
                                Course::class => 'Linked Course',
                                GiftCardType::class => 'Linked Gift Card Type',
                                Costume::class => 'Linked Costume',
                                default => 'Linked Item',
                            })
                            ->options(fn (Get $get) => match ($get('productable_type')) {
                                Course::class => Course::query()->orderBy('name')->pluck('name', 'id'),
                                GiftCardType::class => GiftCardType::query()->orderBy('name')->pluck('name', 'id'),
                                Costume::class => Costume::query()->orderBy('name')->pluck('name', 'id'),
                                default => [],
                            })
                            ->required(fn (Get $get): bool => $get('productable_type') !== null)
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('productable_type') !== null)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if ($get('productable_type') !== GiftCardType::class || $state === null) {
                                    return;
                                }

                                $denomination = GiftCardType::query()->find($state)?->denomination;

                                if ($denomination !== null) {
                                    $set('price', number_format($denomination / 100, 2, '.', ''));
                                }
                            }),
                        Select::make('requires_course_id')
                            ->label('Requires Enrollment In')
                            ->helperText('Only available to users already enrolled in this course.')
                            ->relationship(
                                name: 'requiresCourse',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                            )
                            ->nullable()
                            ->preload()
                            ->visible(fn (Get $get): bool => in_array($get('productable_type'), [Course::class, Costume::class], true)),
                    ]),
                Section::make('Media')
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('include_productable_images')
                            ->label('Include linked item images')
                            ->helperText('Show linked course, costume, or gift card images after product images.')
                            ->default(false)
                            ->visible(fn (Get $get): bool => $get('productable_type') !== null && $get('productable_id') !== null)
                            ->columnSpanFull(),
                        SpatieMediaLibraryFileUpload::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::public())
                            ->visibility('public')
                            ->multiple()
                            ->reorderable()
                            ->image(),
                        SpatieMediaLibraryFileUpload::make('documents')
                            ->collection('documents')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->multiple()
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ]),
                        SpatieMediaLibraryFileUpload::make('videos')
                            ->collection('videos')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->multiple()
                            ->allowVideo(),
                    ]),
            ]);
    }
}
