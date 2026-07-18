<?php

declare(strict_types=1);

use App\Forms\Eac\Blocks\EmergencyContactsBlock;
use App\Forms\Eac\EacFormAssignmentUpgradePolicy;
use App\Forms\Eac\EacFormContentProvider;
use App\Forms\Eac\EacFormMappingProvider;
use App\Forms\Eac\EacFormProjectionHook;
use App\Forms\Eac\EacMedicalWaiverUpdatePolicy;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormAnswerGroup;
use App\Models\FormAssignment;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Models\FormVersion;

return [
    'table_prefix' => 'form_builder_',

    'morph_key_types' => [
        'owner' => 'int',
        'published_by' => 'int',
        'respondent' => 'int',
        'subject' => 'int',
        'projection' => 'int',
        'submitted_by' => 'int',
    ],

    'models' => [
        'form' => Form::class,
        'version' => FormVersion::class,
        'field' => FormField::class,
        'assignment' => FormAssignment::class,
        'response' => FormResponse::class,
        'answer_group' => FormAnswerGroup::class,
        'answer' => FormAnswer::class,
    ],

    'mapping_providers' => [
        EacFormMappingProvider::class,
    ],

    'content_providers' => [
        EacFormContentProvider::class,
    ],

    'projection_hooks' => [
        EacFormProjectionHook::class,
    ],

    'block_providers' => [
        EmergencyContactsBlock::class,
    ],

    'update_policies' => [
        EacMedicalWaiverUpdatePolicy::class,
    ],

    'upgrade_policies' => [
        EacFormAssignmentUpgradePolicy::class,
    ],
];
