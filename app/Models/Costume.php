<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Productable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class Costume extends Model implements Productable
{
    /** @use HasFactory<\Database\Factories\CostumeFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
    ];

    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'productable');
    }

    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool
    {
        return false;
    }
}
