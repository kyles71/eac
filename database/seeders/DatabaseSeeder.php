<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FormTypes;
use App\Models\Course;
use App\Models\Form;
use App\Models\Product;
use App\Models\User;
use Database\Factories\CalendarFactory;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        CalendarFactory::new()->createMany([
            [
                'name' => 'My Calendar',
                'background_color' => null,
            ],
            [
                'name' => 'EAC Calendar',
                'background_color' => '#FF5733',
            ],
        ]);

        Form::factory()
            ->create(['name' => 'Student Waiver 25-26', 'form_type' => FormTypes::STUDENT_WAIVER->value]);

        if (config('app.env') !== 'production') {
            $this->seedDevData();
        }
    }

    private function seedDevData(): void
    {
        User::factory()->create([
            'first_name' => config('app.default_user.first_name'),
            'last_name' => config('app.default_user.last_name'),
            'email' => config('app.default_user.email'),
            'password' => bcrypt(config('app.default_user.password')),
        ]);

        $courses = Course::factory(10)->create();

        // Create a Product for each Course
        $courses->each(function (Course $course): void {
            Product::factory()->forCourse($course)->create();
        });

        User::factory()
            ->count(10)
            ->create();
    }
}
