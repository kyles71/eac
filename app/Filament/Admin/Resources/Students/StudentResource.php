<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students;

use App\Filament\Admin\Resources\Students\Pages\ListStudents;
use App\Filament\Admin\Resources\Students\Pages\ViewStudent;
use App\Filament\Admin\Resources\Students\Schemas\StudentForm;
use App\Filament\Admin\Resources\Students\Schemas\StudentInfolist;
use App\Filament\Admin\Resources\Students\Tables\StudentsTable;
use App\Models\Student;
use App\Models\User;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::PeopleAndAccess;

    protected static ?int $navigationSort = AdminNavigation::PeopleStudents;

    protected static ?string $recordTitleAttribute = 'fullName';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'first_name',
            'last_name',
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user instanceof User
            ? Student::applyAdminAccessConstraint($query, $user)
            : $query->whereRaw('0 = 1');
    }

    public static function form(Schema $schema): Schema
    {
        return StudentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StudentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'view' => ViewStudent::route('/{record}'),
        ];
    }
}
