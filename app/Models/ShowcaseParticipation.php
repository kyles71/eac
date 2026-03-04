<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShowcaseParticipationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class ShowcaseParticipation extends Model
{
    /** @use HasFactory<ShowcaseParticipationFactory> */
    use HasFactory;

    public function userForm(): MorphOne
    {
        return $this->morphOne(FormUser::class, 'responseable');
    }
}
