<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CourseHolds;

use App\Actions\CourseHolds\CreateCourseHold as CreateCourseHoldAction;
use App\Actions\CourseHolds\UpdateCourseHold;
use App\Filament\Admin\Resources\CourseHolds\Pages\ListCourseHolds;
use App\Filament\Admin\Resources\CourseHolds\Pages\ViewCourseHold;
use App\Filament\Admin\Resources\CourseHolds\Schemas\CourseHoldForm;
use App\Filament\Admin\Resources\CourseHolds\Schemas\CourseHoldInfolist;
use App\Filament\Admin\Resources\CourseHolds\Tables\CourseHoldsTable;
use App\Models\CourseHold;
use App\Models\User;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

final class CourseHoldResource extends Resource
{
    protected static ?string $model = CourseHold::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::ClassesAndSchedule;

    protected static ?int $navigationSort = AdminNavigation::ScheduleCourseHolds;

    protected static ?string $navigationLabel = 'Class Holds';

    protected static ?string $modelLabel = 'class hold';

    protected static ?string $pluralModelLabel = 'class holds';

    protected static ?string $recordTitleAttribute = 'id';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return CourseHoldForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CourseHoldInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CourseHoldsTable::configure($table);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->createAnother(false)
            ->using(function (array $data): CourseHold {
                /** @var User $user */
                $user = User::query()->findOrFail((int) $data['user_id']);
                /** @var User|null $admin */
                $admin = auth()->user();

                try {
                    return app(CreateCourseHoldAction::class)->handle(
                        user: $user,
                        expiresAt: Carbon::parse((string) $data['expires_at']),
                        lines: $data['lines'],
                        createdBy: $admin,
                        notes: $data['notes'] ?? null,
                    );
                } catch (InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('Class hold could not be created')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    throw new Halt;
                }
            });
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->visible(fn (CourseHold $record): bool => $record->status()->value !== 'purchased')
            ->using(function (CourseHold $record, array $data): void {
                app(UpdateCourseHold::class)->handle(
                    hold: $record,
                    expiresAt: Carbon::parse((string) $data['expires_at']),
                    notes: $data['notes'] ?? null,
                    additionalLines: $data['additional_lines'] ?? [],
                );
            });
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
            'index' => ListCourseHolds::route('/'),
            'view' => ViewCourseHold::route('/{record}'),
        ];
    }
}
