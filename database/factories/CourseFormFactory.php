<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseForm;
use App\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseForm> */
final class CourseFormFactory extends Factory
{
    protected $model = CourseForm::class;

    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'form_id' => Form::factory(),
        ];
    }
}
