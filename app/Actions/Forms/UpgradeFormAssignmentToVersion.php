<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\FormAssignment;
use App\Models\FormVersion;
use InvalidArgumentException;

final readonly class UpgradeFormAssignmentToVersion
{
    public function __construct(private SaveFormResponseDraft $saveDraft) {}

    public function handle(FormAssignment $assignment, FormVersion $version): void
    {
        if ($assignment->form_id !== $version->form_id || ! $version->isPublished()) {
            throw new InvalidArgumentException('Assignments may only be upgraded to a published version of the same form.');
        }

        $state = $assignment->draftResponse?->response_state;
        $assignment->draftResponse?->delete();
        $assignment->form_version_id = $version->id;
        $assignment->save();

        if (is_array($state)) {
            $this->saveDraft->handle($assignment, $state);
        }
    }
}
