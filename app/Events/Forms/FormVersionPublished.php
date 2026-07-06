<?php

declare(strict_types=1);

namespace App\Events\Forms;

use App\Models\FormVersion;

final readonly class FormVersionPublished
{
    public function __construct(public FormVersion $version) {}
}
