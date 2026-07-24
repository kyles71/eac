<?php

declare(strict_types=1);

namespace App\Forms\Migration;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

final readonly class LegacyCutoverSnapshot
{
    private const int MaximumAgeMinutes = 30;

    public function manifestPath(): string
    {
        return storage_path('app/private/backups/form-builder-cutover-manifest.json');
    }

    /** @param array<string, string> $sourceManifest */
    public function record(string $snapshotPath, string $sourceFingerprint, array $sourceManifest): void
    {
        if (! File::isFile($snapshotPath) || File::size($snapshotPath) === 0) {
            throw new RuntimeException('The legacy form snapshot is missing or empty.');
        }

        $manifestPath = $this->manifestPath();
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode([
            'snapshot_path' => $snapshotPath,
            'snapshot_sha256' => hash_file('sha256', $snapshotPath),
            'source_fingerprint' => $sourceFingerprint,
            'source_manifest' => $sourceManifest,
            'created_at' => now()->utc()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($snapshotPath, 0600);
        chmod($manifestPath, 0600);
    }

    public function assertFresh(string $sourceFingerprint): string
    {
        $manifestPath = $this->manifestPath();

        if (! File::isFile($manifestPath)) {
            throw new RuntimeException('No legacy form cutover snapshot manifest exists. Run forms:legacy-snapshot during maintenance mode.');
        }

        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException('The legacy form cutover snapshot manifest is invalid.');
        }

        $snapshotPath = $manifest['snapshot_path'] ?? null;
        $checksum = $manifest['snapshot_sha256'] ?? null;
        $fingerprint = $manifest['source_fingerprint'] ?? null;
        $createdAt = $manifest['created_at'] ?? null;

        if (! is_string($snapshotPath) || ! File::isFile($snapshotPath) || File::size($snapshotPath) === 0) {
            throw new RuntimeException('The snapshot referenced by the legacy form cutover manifest is missing or empty.');
        }

        if (! is_string($checksum) || ! hash_equals($checksum, hash_file('sha256', $snapshotPath))) {
            throw new RuntimeException('The legacy form cutover snapshot checksum no longer matches its manifest.');
        }

        if (! is_string($fingerprint) || ! hash_equals($fingerprint, $sourceFingerprint)) {
            throw new RuntimeException('Legacy form source data changed after the snapshot. Create a new snapshot before migrating.');
        }

        if (! is_string($createdAt) || Carbon::parse($createdAt)->lt(now()->subMinutes(self::MaximumAgeMinutes))) {
            throw new RuntimeException('The legacy form cutover snapshot is stale. Create a fresh snapshot before migrating.');
        }

        return $snapshotPath;
    }
}
