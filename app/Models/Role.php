<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $weight
 */
final class Role extends SpatieRole
{
    public const string OWNER = 'owner';

    public const string TEACHER = 'teacher';

    public const int SUPER_ADMIN_WEIGHT = 100;

    public const int OWNER_WEIGHT = 50;

    public const int TEACHER_WEIGHT = 10;

    public const string SUPER_ADMIN = 'super_admin';

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        /** @var BelongsToMany<User, $this> $relation */
        $relation = parent::users();

        return $relation;
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }
}
