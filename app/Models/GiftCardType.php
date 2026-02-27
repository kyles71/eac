<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class GiftCardType extends Model
{
    /** @use HasFactory<\Database\Factories\GiftCardTypeFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'denomination' => 'integer',
    ];

    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'productable');
    }

    /**
     * Get the formatted denomination in dollars, or "Custom" if 0.
     */
    public function formattedDenomination(): string
    {
        if ($this->denomination === 0) {
            return 'Custom';
        }

        return '$'.number_format($this->denomination / 100, 2);
    }
}
