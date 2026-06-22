<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\PermissionCatalogSynchronizerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedInteger('weight')->default(0)->index();
        });

        DB::table('roles')->where('name', Role::SUPER_ADMIN)->update(['weight' => Role::SUPER_ADMIN_WEIGHT]);
        DB::table('roles')->where('name', 'owner')->update(['weight' => Role::OWNER_WEIGHT]);
        DB::table('roles')->where('name', 'teacher')->update(['weight' => Role::TEACHER_WEIGHT]);

        app(PermissionCatalogSynchronizerService::class)->sync();
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('weight');
        });
    }
};
