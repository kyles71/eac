<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class CourseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('capacity')
                    ->numeric(),
                TextEntry::make('start_time')
                    ->dateTime(),
                TextEntry::make('duration')
                    ->numeric(),
                TextEntry::make('teacher.full_name')
                    ->label('Teacher\'s Name'),
                TextEntry::make('guest_teacher'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
