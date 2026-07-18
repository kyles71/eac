<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

final class FormField extends \Kyle\FilamentFormBuilder\Models\FormField
{
    /** @use HasFactory<\Database\Factories\FormFieldFactory> */
    use HasFactory;
}
