<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('product_early_access_window_user', function (Blueprint $table) {
            $table->foreignId('product_early_access_window_id');
            $table->foreignId('user_id');
            $table->timestamps();

            $table->primary(['product_early_access_window_id', 'user_id'], 'peaw_user_window_user_primary');
            $table->foreign('product_early_access_window_id', 'peaw_user_window_id_fk')
                ->references('id')
                ->on('product_early_access_windows')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'peaw_user_user_id_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_early_access_window_user');
    }
};
