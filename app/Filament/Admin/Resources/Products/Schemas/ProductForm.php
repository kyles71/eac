<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Models\Course;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? number_format($state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn (?string $state): ?int => $state !== null ? (int) round((float) $state * 100) : null)
                    ->minValue(0),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Select::make('productable_type')
                    ->label('Product Type')
                    ->options([
                        Course::class => 'Course',
                    ])
                    ->reactive(),
                Select::make('productable_id')
                    ->label('Linked Course')
                    ->options(fn () => Course::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn (callable $get): bool => $get('productable_type') === Course::class),
                Select::make('requires_course_id')
                    ->label('Requires Enrollment In')
                    ->helperText('Only visible to users enrolled in this course.')
                    ->relationship(
                        name: 'requiresCourse',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                    )
                    ->nullable()
                    ->searchable()
                    ->preload(),
            ]);
    }
}
