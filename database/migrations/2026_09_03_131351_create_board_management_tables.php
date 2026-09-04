<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('interaction_mode', 30);
            $table->json('allowed_item_types');
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('last_viewed_board_id')
                ->nullable()
                ->constrained('boards')
                ->nullOnDelete();
        });

        Schema::create('board_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 30)->default('gray');
            $table->unsignedSmallInteger('sort_order');
            $table->string('kind', 30)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['board_id', 'name']);
            $table->index(['board_id', 'sort_order']);
        });

        Schema::create('board_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->timestamps();

            $table->unique(['board_id', 'user_id']);
        });

        Schema::create('board_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_stage_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->string('priority', 30)->default('medium');
            $table->string('title', 180);
            $table->longText('description')->nullable();
            $table->decimal('position', 20, 10)->nullable();
            $table->date('due_date')->nullable();
            $table->text('related_url')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['board_stage_id', 'position'], 'unique_position_per_board_stage');
            $table->index(['board_id', 'board_stage_id', 'archived_at']);
        });

        Schema::create('board_item_assignees', function (Blueprint $table): void {
            $table->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['board_item_id', 'user_id']);
        });

        Schema::create('board_item_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('body');
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['board_item_id', 'created_at']);
        });

        Schema::create('board_item_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('muted_at')->nullable();
            $table->timestamps();

            $table->unique(['board_item_id', 'user_id']);
        });

        Schema::create('board_item_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['board_item_id', 'created_at']);
        });

        $this->createPortalPlanningBoard();
        $this->grantBoardPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissions())
            ->delete();

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_viewed_board_id');
        });

        Schema::dropIfExists('board_item_activities');
        Schema::dropIfExists('board_item_subscriptions');
        Schema::dropIfExists('board_item_comments');
        Schema::dropIfExists('board_item_assignees');
        Schema::dropIfExists('board_items');
        Schema::dropIfExists('board_memberships');
        Schema::dropIfExists('board_stages');
        Schema::dropIfExists('boards');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createPortalPlanningBoard(): void
    {
        $now = now();
        $boardId = DB::table('boards')->insertGetId([
            'created_by_user_id' => null,
            'name' => 'Portal Planning',
            'slug' => 'portal-planning',
            'description' => 'Share bugs, feature requests, and early ideas, then follow their progress.',
            'interaction_mode' => 'moderated',
            'allowed_item_types' => json_encode(['bug', 'feature_request', 'idea'], JSON_THROW_ON_ERROR),
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('board_stages')->insert([
            $this->stage($boardId, 'Future Ideas', 'gray', 10, 'active', true, $now),
            $this->stage($boardId, 'Under Review', 'info', 20, 'active', false, $now),
            $this->stage($boardId, 'Ready to Build', 'primary', 30, 'active', false, $now),
            $this->stage($boardId, 'In Progress', 'warning', 40, 'active', false, $now),
            $this->stage($boardId, 'Ready for Testing', 'info', 50, 'active', false, $now),
            $this->stage($boardId, 'Released', 'success', 60, 'completed', false, $now),
            $this->stage($boardId, 'Not Planned', 'gray', 70, 'cancelled', false, $now),
        ]);
    }

    /** @return array<string, mixed> */
    private function stage(int $boardId, string $name, string $color, int $sortOrder, string $kind, bool $isDefault, mixed $now): array
    {
        return [
            'board_id' => $boardId,
            'name' => $name,
            'color' => $color,
            'sort_order' => $sortOrder,
            'kind' => $kind,
            'is_default' => $isDefault,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function grantBoardPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach ([Role::OWNER, Role::SUPER_ADMIN] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($this->permissions());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function permissions(): array
    {
        return [
            'ViewAny:Board',
            'View:Board',
            'Create:Board',
            'Update:Board',
            'Delete:Board',
            'ViewAny:BoardItem',
            'View:BoardItem',
            'Update:BoardItem',
            'ManageMembers:Board',
        ];
    }
};
