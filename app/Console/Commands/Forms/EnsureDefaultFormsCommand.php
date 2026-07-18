<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

use App\Forms\DefaultFormDefinitions;
use App\Forms\Eac\EacFormContentProvider;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Kyle\FilamentFormBuilder\Actions\InstallFormBlueprint;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;

#[Signature('forms:ensure-defaults')]
#[Description('Idempotently ensure seasonal waiver versions and the manual showcase template')]
final class EnsureDefaultFormsCommand extends Command
{
    public function handle(DefaultFormDefinitions $definitions, InstallFormBlueprint $installer): int
    {
        $this->ensureWaiverVersion($definitions, $installer, now());
        $this->ensureWaiverVersion($definitions, $installer, now()->addYear());

        $showcase = Form::query()->firstOrCreate(
            ['key' => 'showcase-participation'],
            [
                'name' => 'Showcase Participation',
                'updates_allowed' => false,
                'update_strategy' => null,
            ],
        );
        $showcase->versions()->where('status', FormVersionStatus::Draft)->first()
            ?? $showcase->createDraftVersion(
                schema: $definitions->showcaseParticipation(),
                requiresSignature: true,
                label: 'Showcase template — copy and label for an actual event',
            );

        $this->components->info('Current and next waiver seasons and the manual showcase template are ready.');

        return self::SUCCESS;
    }

    private function ensureWaiverVersion(
        DefaultFormDefinitions $definitions,
        InstallFormBlueprint $installer,
        CarbonInterface $at,
    ): void {
        $existing = Form::query()
            ->where('key', 'student-waiver')
            ->first()
            ?->versions()
            ->where('version_key', $definitions->studentWaiverVersionKey($at))
            ->first();
        [$healthReference, $textReference] = $existing instanceof FormVersion
            ? $this->pinnedReferences($existing->schema)
            : [null, null];

        $installer->handle(
            $definitions->studentWaiverBlueprint(
                at: $at,
                healthSafetyPolicyReference: $healthReference,
                textMessageUpdatesPolicyReference: $textReference,
            ),
            $this->publisher(),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{string|null, string|null}
     */
    private function pinnedReferences(array $blocks): array
    {
        $health = null;
        $text = null;

        foreach ($blocks as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $helpReference = $data['help_reference'] ?? null;
            $textReference = $data['text_message_policy_reference'] ?? null;

            if (is_string($helpReference) && str_starts_with($helpReference, EacFormContentProvider::HealthSafetyPolicyVersionPrefix)) {
                $health = $helpReference;
            }

            if (is_string($textReference) && str_starts_with($textReference, EacFormContentProvider::TextMessageUpdatesPolicyVersionPrefix)) {
                $text = $textReference;
            }

            if (is_array($data['components'] ?? null)) {
                [$nestedHealth, $nestedText] = $this->pinnedReferences($data['components']);
                $health ??= $nestedHealth;
                $text ??= $nestedText;
            }
        }

        return [$health, $text];
    }

    private function publisher(): ?Model
    {
        return User::query()->where('email', config('app.default_user.email'))->first();
    }
}
