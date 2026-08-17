<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Enrollments;

use App\Filament\Admin\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Admin\Resources\Enrollments\Schemas\EnrollmentForm;
use App\Filament\Admin\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::ClassesAndSchedule;

    protected static ?int $navigationSort = AdminNavigation::ScheduleEnrollments;

    public static function form(Schema $schema): Schema
    {
        return EnrollmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EnrollmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrollments::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user instanceof User && ! $user->hasAnyRole(['owner', 'super_admin'])) {
            $query->whereHas('course', fn (Builder $query): Builder => $query
                ->where('is_private', false)
                ->orWhereHas('teachers', fn (Builder $query): Builder => $query->whereKey($user->id)));
        }

        return $query;
    }
}
