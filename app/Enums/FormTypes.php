<?php

declare(strict_types=1);

namespace App\Enums;

use App\Filament\Schemas\ShowcaseParticipation;
use App\Filament\Schemas\StudentWaiver;
use App\Models\ShowcaseParticipation as ModelsShowcaseParticipation;
use App\Models\StudentWaiver as ModelsStudentWaiver;
use Filament\Support\Contracts\HasLabel;

enum FormTypes: string implements HasLabel
{
    case STUDENT_WAIVER = ModelsStudentWaiver::class;
    case SHOWCASE_PARTICIPATIONS = ModelsShowcaseParticipation::class;

    public function getLabel(): string
    {
        return match ($this) {
            self::STUDENT_WAIVER => 'Student Waiver',
            self::SHOWCASE_PARTICIPATIONS => 'Showcase Participations',
        };
    }

    public function getFormSchemaClass(): string
    {
        return match ($this) {
            self::STUDENT_WAIVER => StudentWaiver::class,
            self::SHOWCASE_PARTICIPATIONS => ShowcaseParticipation::class,
        };
    }
}
