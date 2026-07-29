<?php

declare(strict_types=1);

namespace Tests;

use App\Models\LegalDocument;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return true;
            }
        });

        $this->seed(ShieldSeeder::class);

        $this->ensurePaymentPlanTermsDocumentExists();

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

    private function ensurePaymentPlanTermsDocumentExists(): void
    {
        $document = LegalDocument::query()->firstOrCreate(
            ['key' => 'payment_plan_terms'],
            [
                'name' => 'Payment Plan Terms & Conditions',
                'description' => 'Terms accepted before purchasing with a payment plan.',
            ],
        );

        $document->currentVersion() ?? $document->publishVersion(
            title: 'Payment Plan Terms & Conditions',
            content: '<p>Payment plan terms and conditions apply to payment plan purchases.</p>',
        );
    }
}
