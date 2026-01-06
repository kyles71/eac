<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'first_name' => config('app.default_user.first_name'),
            'last_name' => config('app.default_user.last_name'),
            'email' => config('app.default_user.email'),
            'password' => config('app.default_user.password'),
        ]));

        $this->withoutVite();
    }
}
