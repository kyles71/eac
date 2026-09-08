<?php

declare(strict_types=1);

namespace App\Forms\Eac;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Kyle\FilamentFormBuilder\Contracts\FormResponseQueryScope;
use Kyle\FilamentFormBuilder\Models\Form;

final readonly class EacFormResponseQueryScope implements FormResponseQueryScope
{
    public function apply(Builder $query, Form $form, ?Authenticatable $actor): Builder
    {
        return $actor instanceof User && $actor->can('View:Form')
            ? $query
            : $query->whereRaw('1 = 0');
    }
}
