<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductEarlyAccessWindowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ProductEarlyAccessWindow extends Model
{
    /** @use HasFactory<ProductEarlyAccessWindowFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'product_id' => 'integer',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'audiences' => 'array',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'product_early_access_window_user')
            ->withTimestamps();
    }
}
