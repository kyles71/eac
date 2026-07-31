<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\RelationManagers;

use App\Filament\Admin\Resources\StaffNotes\StaffNoteResource;
use App\Models\StaffNote;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class StaffNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'staffNotes';

    protected static ?string $relatedResource = StaffNoteResource::class;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Student
            && Gate::allows('view', $ownerRecord)
            && Gate::allows('viewAny', StaffNote::class);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Staff Notes')
            ->headerActions([
                CreateAction::make()
                    ->label('Add staff note')
                    ->mutateDataUsing(function (array $data): array {
                        $author = auth()->user();

                        if (! $author instanceof User) {
                            throw new LogicException('Staff notes require an authenticated author.');
                        }

                        $data['author_id'] = $author->id;

                        return $data;
                    }),
            ]);
    }
}
