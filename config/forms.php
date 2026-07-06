<?php

declare(strict_types=1);

use App\Forms\Eac\EacFormMappingProvider;
use App\Forms\Eac\EacMedicalWaiverUpdatePolicy;

return [
    /*
    |--------------------------------------------------------------------------
    | Dynamic form mapping providers
    |--------------------------------------------------------------------------
    |
    | Providers keep the reusable form engine decoupled from project-specific
    | projections such as EAC medical waivers and showcase participation.
    |
    */
    'mapping_providers' => [
        EacFormMappingProvider::class,
    ],

    'update_policies' => [
        EacMedicalWaiverUpdatePolicy::class,
    ],
];
