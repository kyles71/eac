<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Tags\Tag;

return new class() extends Migration
{
    public function up(): void
    {
        Tag::findFromString('Comp', 'calendar-audience')?->delete();
    }

    public function down(): void
    {
        Tag::findOrCreate('Comp', 'calendar-audience');
    }
};
