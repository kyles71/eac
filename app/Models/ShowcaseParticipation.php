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

    public function formResponse(): MorphOne
    {
        return $this->morphOne(FormResponse::class, 'projection');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_participating' => 'boolean',
        ];
    }
}
