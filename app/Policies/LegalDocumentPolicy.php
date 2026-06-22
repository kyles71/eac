<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LegalDocument;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class LegalDocumentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LegalDocument');
    }

    public function publish(AuthUser $authUser, LegalDocument $legalDocument): bool
    {
        return $authUser->can('Publish:LegalDocument');
    }
}
