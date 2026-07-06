<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Enums\FormVersionStatus;
use App\Events\Forms\FormVersionPublished;
use App\Forms\FormDefinition;
use App\Forms\FormMappingRegistry;
use App\Models\FormAssignment;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class PublishFormVersion
{
    public function __construct(
        private FormDefinition $definition,
        private FormMappingRegistry $mappingRegistry,
        private UpgradeFormAssignmentToVersion $upgradeAssignment,
    ) {}

    public function handle(FormVersion $version, ?User $publisher, bool $requireCompletedAgain = false): FormVersion
    {
        $published = DB::transaction(function () use ($version, $publisher, $requireCompletedAgain): FormVersion {
            $version = FormVersion::query()
                ->with('form')
                ->lockForUpdate()
                ->findOrFail($version->id);

            if (! $version->isDraft()) {
                throw new InvalidArgumentException('Only a draft form version can be published.');
            }

            $nextVersion = ((int) $version->form
                ->versions()
                ->where('status', FormVersionStatus::Published)
                ->max('version')) + 1;

            if ($version->version !== $nextVersion) {
                throw new InvalidArgumentException("The next published form version must be version {$nextVersion}.");
            }

            $this->definition->validate($version->schema);
            $fieldDefinitions = collect($this->definition->fields($version->schema));

            if ($fieldDefinitions->isEmpty()) {
                throw new InvalidArgumentException('A form version must contain at least one answer field.');
            }

            if ($fieldDefinitions->pluck('key')->duplicates()->isNotEmpty()) {
                throw new InvalidArgumentException('Every form field must have a unique stable key.');
            }

            if ($fieldDefinitions->pluck('mapping')->filter()->duplicates()->isNotEmpty()) {
                throw new InvalidArgumentException('Each curated mapping may only be used once per form version.');
            }

            foreach ($fieldDefinitions as $field) {
                $this->mappingRegistry->validate($version->form->purpose, $field['answer_type'], $field['mapping']);
            }

            foreach ($fieldDefinitions as $fieldDefinition) {
                $field = FormField::query()->firstOrNew([
                    'form_id' => $version->form_id,
                    'key' => $fieldDefinition['key'],
                ]);

                if (
                    $field->exists
                    && ($field->answer_type !== $fieldDefinition['answer_type'] || $field->mapping !== $fieldDefinition['mapping'])
                ) {
                    throw new InvalidArgumentException("Field [{$fieldDefinition['label']}] changed its answer type or mapping. Replace it with a new field instead.");
                }

                $field->answer_type = $fieldDefinition['answer_type'];
                $field->mapping = $fieldDefinition['mapping'];
                $field->label = $fieldDefinition['label'];
                $field->block_key = $fieldDefinition['block_key'];
                $field->sub_key = $fieldDefinition['sub_key'];
                $field->save();
            }

            $version->status = FormVersionStatus::Published;
            $version->published_by_id = $publisher?->id;
            $version->published_at = now();
            $version->save();

            FormAssignment::query()
                ->where('form_id', $version->form_id)
                ->with(['draftResponse', 'latestSubmittedResponse'])
                ->get()
                ->each(function (FormAssignment $assignment) use ($version, $requireCompletedAgain): void {
                    if (! $requireCompletedAgain && $assignment->isCompleted()) {
                        return;
                    }

                    $this->upgradeAssignment->handle($assignment, $version);
                });

            return $version->refresh();
        });

        event(new FormVersionPublished($published));

        return $published;
    }
}
