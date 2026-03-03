<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('gift_card_types', function (Blueprint $table) {
            $table->string('restricted_to_product_type')->nullable()->after('denomination');
        });
    }
};
