<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ShieldSeeder::class);

        $user = User::factory()->create([
            'first_name' => config('app.default_user.first_name'),
            'last_name' => config('app.default_user.last_name'),
            'email' => config('app.default_user.email'),
            'password' => config('app.default_user.password'),
        ]);

        $user->assignRole('super_admin');

        $this->actingAs($user);

        $this->withoutVite();
    }
}
