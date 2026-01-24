<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\RelationManagers;

use App\Filament\Resources\Courses\Schemas\CourseForm;
use App\Filament\Resources\Courses\Schemas\CourseInfolist;
use App\Filament\Resources\Courses\Tables\CoursesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

final class CoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'courses';

    public function form(Schema $schema): Schema
    {
        return CourseForm::configure($schema);
    }

    public function infolist(Schema $schema): Schema
    {
        return CourseInfolist::configure($schema);
    }

    public function table(Table $table): Table
    {
        return CoursesTable::configure($table);
    }
}
