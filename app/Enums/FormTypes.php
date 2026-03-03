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
    case StudentWaiver = ModelsStudentWaiver::class;
    case ShowcaseParticipation = ModelsShowcaseParticipation::class;

    public function getLabel(): string
    {
        return match ($this) {
            self::StudentWaiver => 'Student Waiver',
            self::ShowcaseParticipation => 'Showcase Participations',
        };
    }

    public function getFormSchemaClass(): string
    {
        return match ($this) {
            self::StudentWaiver => StudentWaiver::class,
            self::ShowcaseParticipation => ShowcaseParticipation::class,
        };
    }
}
