<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers\Pages;

use App\Filament\Admin\Resources\FormUsers\FormUserResource;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Kyle\FilamentFormBuilder\Actions\UpgradeFormAssignmentToVersion;
use LogicException;

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
                            ->whereHas('currentVersion', fn (Builder $query): Builder => $query->active())
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
                        ->required()
                        ->searchable(),
                    Select::make('student_id')
                        ->label('Student')
                        ->options(fn (): array => Student::query()
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(fn (Student $student): array => [$student->id => $student->fullName])
                            ->all())
                        ->required(fn (Get $get): bool => Form::query()
                            ->whereKey($get('form_id'))
                            ->whereIn('key', ['student-waiver', 'showcase-participation'])
                            ->exists())
                        ->searchable(),
                ])
                ->using(function (array $data): FormAssignment {
                    $form = Form::query()->with('currentVersion')->findOrFail($data['form_id']);
                    $version = $form->currentVersion;

                    if ($version === null || ! $version->isActive()) {
                        throw new LogicException('Only a currently active form can be assigned manually.');
                    }

                    $respondent = User::query()->findOrFail($data['respondent_id']);
                    $student = filled($data['student_id'] ?? null)
                        ? Student::query()->findOrFail($data['student_id'])
                        : null;
                    $assignment = FormAssignment::query()
                        ->where('form_id', $form->id)
                        ->when(
                            $student instanceof Student,
                            fn (Builder $query): Builder => $query->forSubject($student),
                            fn (Builder $query): Builder => $query
                                ->whereNull('subject_type')
                                ->whereNull('subject_id')
                                ->forRespondent($respondent),
                        )
                        ->first();

                    if ($assignment === null) {
                        $assignment = new FormAssignment([
                            'form_id' => $form->id,
                            'form_version_id' => $version->id,
                        ]);
                        $assignment->respondent()->associate($respondent);
                        $assignment->subject()->associate($student);
                        $assignment->save();

                        return $assignment;
                    }

                    $assignment->respondent()->associate($respondent);
                    $assignment->save();

                    if ($assignment->form_version_id !== $version->id) {
                        app(UpgradeFormAssignmentToVersion::class)->handle($assignment, $version);
                    }

                    return $assignment->refresh();
                }),
        ];
    }
}
