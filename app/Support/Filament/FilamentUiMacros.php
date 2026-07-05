<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Models\Student;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use LogicException;

final class FilamentUiMacros
{
    public static function phone(object $field): TextInput
    {
        if (! $field instanceof TextInput) {
            throw new LogicException('The phone macro must be bound to a text input.');
        }

        return $field->mask('(999) 999-9999')
            ->prefixIcon('heroicon-o-phone')
            ->tel()
            ->minLength(14)
            ->maxLength(14)
            ->validationMessages([
                'min' => 'Please enter a valid phone number including area code.',
            ]);
    }

    public static function textInputMoneyCents(object $field, float|int $minValue = 0): TextInput
    {
        if (! $field instanceof TextInput) {
            throw new LogicException('The moneyCents macro must be bound to a text input.');
        }

        return $field
            ->numeric()
            ->prefix('$')
            ->minValue($minValue)
            ->formatStateUsing(fn (mixed $state): ?string => is_numeric($state) ? number_format(((int) $state) / 100, 2, '.', '') : null)
            ->dehydrateStateUsing(fn (mixed $state): ?int => filled($state) ? (int) round(((float) str_replace(',', '', (string) $state)) * 100) : null);
    }

    public static function textColumnMoneyCents(object $column, ?string $placeholder = null): TextColumn
    {
        if (! $column instanceof TextColumn) {
            throw new LogicException('The moneyCents macro must be bound to a text column.');
        }

        $column = $column->formatStateUsing(fn (mixed $state): ?string => is_numeric($state) ? format_money((int) $state) : null);

        if ($placeholder !== null) {
            $column->placeholder($placeholder);
        }

        return $column;
    }

    public static function textEntryMoneyCents(object $entry, ?string $placeholder = null): TextEntry
    {
        if (! $entry instanceof TextEntry) {
            throw new LogicException('The moneyCents macro must be bound to a text entry.');
        }

        $entry = $entry->formatStateUsing(fn (mixed $state): ?string => is_numeric($state) ? format_money((int) $state) : null);

        if ($placeholder !== null) {
            $entry->placeholder($placeholder);
        }

        return $entry;
    }

    /**
     * @param  list<string>  $searchColumns
     * @param  array<int, string>|array<string, string>  $orderBy
     */
    public static function searchableRelationship(
        object $select,
        string $name,
        array $searchColumns,
        Closure $labelFromRecord,
        ?Closure $modifyQueryUsing = null,
        array $orderBy = [],
        string $titleAttribute = 'id',
    ): Select {
        if (! $select instanceof Select) {
            throw new LogicException('The searchableRelationship macro must be bound to a select.');
        }

        return SelectSearch::relationship(
            select: $select,
            name: $name,
            searchColumns: $searchColumns,
            labelFromRecord: $labelFromRecord,
            modifyQueryUsing: $modifyQueryUsing,
            orderBy: $orderBy,
            titleAttribute: $titleAttribute,
        );
    }

    public static function userRelationship(
        object $select,
        string $name = 'user',
        ?Closure $modifyQueryUsing = null,
    ): Select {
        return self::searchableRelationship(
            select: $select,
            name: $name,
            searchColumns: ['first_name', 'last_name', 'email'],
            labelFromRecord: fn (User $user): string => filled($user->email)
                ? "{$user->fullName} ({$user->email})"
                : $user->fullName,
            modifyQueryUsing: $modifyQueryUsing,
            orderBy: ['first_name', 'last_name'],
        );
    }

    public static function studentRelationship(
        object $select,
        string $name = 'student',
        ?Closure $modifyQueryUsing = null,
    ): Select {
        return self::searchableRelationship(
            select: $select,
            name: $name,
            searchColumns: ['first_name', 'last_name'],
            labelFromRecord: fn (Student $student): string => $student->fullName,
            modifyQueryUsing: $modifyQueryUsing,
            orderBy: ['first_name', 'last_name'],
        );
    }

    public static function allowVideo(object $upload): SpatieMediaLibraryFileUpload
    {
        if (! $upload instanceof SpatieMediaLibraryFileUpload) {
            throw new LogicException('The allowVideo macro must be bound to a media library file upload.');
        }

        return $upload
            ->maxSize(config('app.file_uploads.video_max_size_kilobytes'))
            ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime']);
    }
}
