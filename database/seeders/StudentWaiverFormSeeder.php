<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Forms\DefaultFormDefinitions;
use App\Forms\Eac\EacFormContentProvider;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Kyle\FilamentFormBuilder\Actions\InstallFormBlueprint;
use Kyle\FilamentFormBuilder\Support\FormSchemaDocument;

final class StudentWaiverFormSeeder extends Seeder
{
    public function run(
        DefaultFormDefinitions $definitions,
        InstallFormBlueprint $installer,
    ): void {
        $form = Form::query()
            ->where('key', 'student-waiver')
            ->first();
        $existingVersion = $form instanceof Form
            ? $form->versions()->where('version_key', $definitions->studentWaiverVersionKey())->first()
            : null;
        $healthSafetyPolicyReference = $existingVersion instanceof FormVersion
            ? $this->healthSafetyPolicyReference($existingVersion->schema)
            : null;
        $textMessageUpdatesPolicyReference = $existingVersion instanceof FormVersion
            ? $this->textMessageUpdatesPolicyReference($existingVersion->schema)
            : null;

        $installer->handle(
            $definitions->studentWaiverBlueprint(
                healthSafetyPolicyReference: $healthSafetyPolicyReference,
                textMessageUpdatesPolicyReference: $textMessageUpdatesPolicyReference,
            ),
            $this->publisher(),
        );
    }

    private function publisher(): ?User
    {
        return User::query()
            ->where('email', config('app.default_user.email'))
            ->first();
    }

    /** @param array<string, mixed>|array<int, array<string, mixed>> $blocks */
    private function healthSafetyPolicyReference(array $blocks): ?string
    {
        foreach (FormSchemaDocument::components($blocks) as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if (($data['key'] ?? null) === DefaultFormDefinitions::HealthSafetyPolicyConsent) {
                $reference = $data['help_reference'] ?? null;

                return is_string($reference) && str_starts_with($reference, EacFormContentProvider::HealthSafetyPolicyVersionPrefix)
                    ? $reference
                    : null;
            }

            if (is_array($data['components'] ?? null)) {
                $reference = $this->healthSafetyPolicyReference($data['components']);

                if ($reference !== null) {
                    return $reference;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed>|array<int, array<string, mixed>> $blocks */
    private function textMessageUpdatesPolicyReference(array $blocks): ?string
    {
        foreach (FormSchemaDocument::components($blocks) as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if (($data['key'] ?? null) === DefaultFormDefinitions::EmergencyContacts) {
                $reference = $data['text_message_policy_reference'] ?? null;

                return is_string($reference) && str_starts_with($reference, EacFormContentProvider::TextMessageUpdatesPolicyVersionPrefix)
                    ? $reference
                    : null;
            }

            if (is_array($data['components'] ?? null)) {
                $reference = $this->textMessageUpdatesPolicyReference($data['components']);

                if ($reference !== null) {
                    return $reference;
                }
            }
        }

        return null;
    }
}
