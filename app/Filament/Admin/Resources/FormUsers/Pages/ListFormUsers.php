<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers\Pages;

use App\Actions\Forms\AssignFormManually;
use App\Filament\Admin\Resources\FormUsers\FormUserResource;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormVersion;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;

final class ListFormUsers extends ListRecords
{
    protected static string $resource = FormUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Assign Form')
                ->schema([
                    Select::make('form_id')
                        ->label('Active Form')
                        ->options(fn (): array => Form::query()
                            ->isActive()
                            ->whereHas('currentVersion', function (Builder $query): void {
                                FormVersion::applyActiveConstraint($query);
                            })
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required()
                        ->searchable()
                        ->live(),
                    Select::make('respondent_id')
                        ->label('Parent / User')
                        ->options(fn (): array => User::query()
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName])
                            ->all())
                        ->required(fn (Get $get): bool => ! self::isStudentForm($get('form_id')) && blank($get('student_id')))
                        ->visible(fn (Get $get): bool => ! self::isStudentForm($get('form_id')) && blank($get('student_id')))
                        ->searchable(),
                    Select::make('student_id')
                        ->label('Student')
                        ->options(fn (): array => Student::query()
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(fn (Student $student): array => [$student->id => $student->fullName])
                            ->all())
                        ->required(fn (Get $get): bool => self::isStudentForm($get('form_id')))
                        ->live()
                        ->searchable(),
                ])
                ->using(function (array $data): FormAssignment {
                    $form = Form::query()->findOrFail($data['form_id']);
                    $student = filled($data['student_id'] ?? null)
                        ? Student::query()->findOrFail($data['student_id'])
                        : null;
                    $respondent = filled($data['respondent_id'] ?? null)
                        ? User::query()->findOrFail($data['respondent_id'])
                        : null;
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        abort(403);
                    }

                    return app(AssignFormManually::class)->handle($form, $student, $respondent, $actor);
                }),
        ];
    }

    private static function isStudentForm(mixed $formId): bool
    {
        return is_numeric($formId) && Form::query()
            ->whereKey((int) $formId)
            ->whereIn('key', ['student-waiver', 'showcase-participation'])
            ->exists();
    }
}
