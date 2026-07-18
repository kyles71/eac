<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 */
final class FormAssignment extends \Kyle\FilamentFormBuilder\Models\FormAssignment
{
    /** @use HasFactory<\Database\Factories\FormAssignmentFactory> */
    use HasFactory;
}
