<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PermissionCatalogSynchronizerService;
use Illuminate\Console\Command;

final class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync {--dry-run : Show catalog changes without writing them}';

    protected $description = 'Synchronize the database and super administrator role with the Shield permission catalog';

    public function handle(PermissionCatalogSynchronizerService $synchronizer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changes = $dryRun ? $synchronizer->changes() : $synchronizer->sync();

        $this->components->info($dryRun ? 'Permission catalog dry run' : 'Permission catalog synchronized');
        $this->components->twoColumnDetail('Create', (string) count($changes['created']));
        $this->components->twoColumnDetail('Delete', (string) count($changes['deleted']));
        $this->components->twoColumnDetail('Retain', (string) count($changes['retained']));

        if ($changes['created'] !== []) {
            $this->newLine();
            $this->components->info('Permissions to create');
            $this->components->bulletList($changes['created']);
        }

        if ($changes['deleted'] !== []) {
            $this->newLine();
            $this->components->warn('Permissions to delete');
            $this->components->bulletList($changes['deleted']);
        }

        return self::SUCCESS;
    }
}
