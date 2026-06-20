<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\Students;

use App\Filament\User\Resources\Students\Pages\CreateStudent;
use App\Filament\User\Resources\Students\Pages\ListStudents;
use App\Filament\User\Resources\Students\Pages\ViewStudent;
use App\Filament\User\Resources\Students\Schemas\StudentForm;
use App\Filament\User\Resources\Students\Tables\StudentsTable;
use App\Models\Student;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static ?string $slug = 'students';

    protected static ?string $modelLabel = 'Student';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'fullName';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id());
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return auth()->check()
            ? Response::allow()
            : Response::deny();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return auth()->check()
            ? Response::allow()
            : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return self::ownsStudent($record)
            ? Response::allow()
            : Response::deny();
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return self::ownsStudent($record)
            ? Response::allow()
            : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return self::canDeleteStudent($record)
            ? Response::allow()
            : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return StudentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'create' => CreateStudent::route('/create'),
            'view' => ViewStudent::route('/{record}'),
        ];
    }

    private static function ownsStudent(Model $record): bool
    {
        return $record instanceof Student
            && $record->user_id === auth()->id();
    }

    private static function canDeleteStudent(Model $record): bool
    {
        return self::ownsStudent($record)
            && $record->enrollments()->doesntExist();
    }
}
