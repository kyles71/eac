<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const string OLD_MODEL_TYPE = 'App\\Models\\Costume';

    private const string NEW_MODEL_TYPE = 'App\\Models\\Gear';

    /** @var list<string> */
    private const array PERMISSION_PREFIXES = ['ViewAny', 'Create', 'Update', 'Delete', 'DeleteAny'];

    public function up(): void
    {
        $this->ensureReleaseIsSafe();

        Schema::rename('costumes', 'gear');

        $this->renameModelType(self::OLD_MODEL_TYPE, self::NEW_MODEL_TYPE);
        $this->renameProductType('Costume', 'Gear');
        $this->renamePermissions('Costume', 'Gear');
        $this->renameReceiptConditionalSection('costume', 'gear');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->renameReceiptConditionalSection('gear', 'costume');
        $this->renamePermissions('Gear', 'Costume');
        $this->renameProductType('Gear', 'Costume');
        $this->renameModelType(self::NEW_MODEL_TYPE, self::OLD_MODEL_TYPE);

        Schema::rename('gear', 'costumes');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureReleaseIsSafe(): void
    {
        $costumeProductIds = DB::table('products')
            ->where('productable_type', self::OLD_MODEL_TYPE)
            ->pluck('id');

        if ($costumeProductIds->isEmpty()) {
            return;
        }

        $releaseAt = now();

        if (DB::table('products')
            ->whereIn('id', $costumeProductIds)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($releaseAt): void {
                $query
                    ->whereNull('available_until')
                    ->orWhere('available_until', '>', $releaseAt);
            })
            ->exists()) {
            throw new RuntimeException('Costume-to-Gear migration blocked: at least one linked product is active and has not expired.');
        }

        if (DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_id', $costumeProductIds)
            ->whereIn('orders.status', ['Pending', 'Processing'])
            ->exists()) {
            throw new RuntimeException('Costume-to-Gear migration blocked: at least one linked product has an in-flight order.');
        }
    }

    private function renameModelType(string $from, string $to): void
    {
        DB::table('products')
            ->where('productable_type', $from)
            ->update(['productable_type' => $to]);

        DB::table('media')
            ->where('model_type', $from)
            ->update(['model_type' => $to]);
    }

    private function renameProductType(string $from, string $to): void
    {
        foreach ([
            'payment_plan_templates' => 'product_type',
            'gift_card_types' => 'restricted_to_product_type',
            'credit_grants' => 'restricted_to_product_type',
        ] as $table => $column) {
            DB::table($table)
                ->where($column, $from)
                ->update([$column => $to]);
        }
    }

    private function renamePermissions(string $from, string $to): void
    {
        $permissionsTable = (string) config('permission.table_names.permissions', 'permissions');
        $rolePermissionsTable = (string) config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $modelPermissionsTable = (string) config('permission.table_names.model_has_permissions', 'model_has_permissions');
        $permissionPivotKey = (string) config('permission.column_names.permission_pivot_key', 'permission_id');

        foreach (self::PERMISSION_PREFIXES as $prefix) {
            $oldPermission = DB::table($permissionsTable)
                ->where('name', "{$prefix}:{$from}")
                ->where('guard_name', 'web')
                ->first(['id']);

            if ($oldPermission === null) {
                continue;
            }

            $newPermission = DB::table($permissionsTable)
                ->where('name', "{$prefix}:{$to}")
                ->where('guard_name', 'web')
                ->first(['id']);

            if ($newPermission === null) {
                DB::table($permissionsTable)
                    ->where('id', $oldPermission->id)
                    ->update(['name' => "{$prefix}:{$to}"]);

                continue;
            }

            $this->movePermissionAssignments(
                $rolePermissionsTable,
                $permissionPivotKey,
                (int) $oldPermission->id,
                (int) $newPermission->id,
            );
            $this->movePermissionAssignments(
                $modelPermissionsTable,
                $permissionPivotKey,
                (int) $oldPermission->id,
                (int) $newPermission->id,
            );

            DB::table($permissionsTable)->where('id', $oldPermission->id)->delete();
        }
    }

    private function movePermissionAssignments(
        string $table,
        string $permissionPivotKey,
        int $fromPermissionId,
        int $toPermissionId,
    ): void {
        $assignments = DB::table($table)
            ->where($permissionPivotKey, $fromPermissionId)
            ->get();

        foreach ($assignments as $assignment) {
            $attributes = (array) $assignment;
            $attributes[$permissionPivotKey] = $toPermissionId;

            DB::table($table)->insertOrIgnore($attributes);
        }
    }

    private function renameReceiptConditionalSection(string $from, string $to): void
    {
        $templatesTable = (string) config('fin-mail.table_names.templates', 'email_templates');
        $versionsTable = (string) config('fin-mail.table_names.versions', 'email_template_versions');
        $template = DB::table($templatesTable)
            ->where('key', 'order-receipt')
            ->first(['id', 'body', 'conditional_sections']);

        if ($template === null) {
            return;
        }

        DB::table($templatesTable)
            ->where('id', $template->id)
            ->update([
                'body' => $this->renameMergeTagInJson($template->body, $from, $to),
                'conditional_sections' => $this->renameJsonKey($template->conditional_sections, $from, $to),
            ]);

        DB::table($versionsTable)
            ->where('email_template_id', $template->id)
            ->select(['id', 'body', 'manager_snapshot'])
            ->orderBy('id')
            ->each(function (object $version) use ($versionsTable, $from, $to): void {
                DB::table($versionsTable)
                    ->where('id', $version->id)
                    ->update([
                        'body' => $this->renameMergeTagInJson($version->body, $from, $to),
                        'manager_snapshot' => $this->renameManagerSnapshot($version->manager_snapshot, $from, $to),
                    ]);
            });
    }

    private function renameMergeTagInJson(mixed $json, string $from, string $to): mixed
    {
        $decoded = $this->decodeJson($json);

        if ($decoded === null) {
            return $json;
        }

        return $this->encodeJson($this->replaceMergeTags($decoded, $from, $to));
    }

    private function renameJsonKey(mixed $json, string $from, string $to): mixed
    {
        $decoded = $this->decodeJson($json);

        if ($decoded === null) {
            return $json;
        }

        return $this->encodeJson($this->renameKey($decoded, $from, $to));
    }

    private function renameManagerSnapshot(mixed $json, string $from, string $to): mixed
    {
        $snapshot = $this->decodeJson($json);

        if ($snapshot === null) {
            return $json;
        }

        foreach (['body', 'effective_body'] as $bodyKey) {
            if (array_key_exists($bodyKey, $snapshot)) {
                $snapshot[$bodyKey] = $this->replaceMergeTags($snapshot[$bodyKey], $from, $to);
            }
        }

        foreach (['conditional_sections', 'effective_conditional_sections'] as $sectionsKey) {
            if (isset($snapshot[$sectionsKey]) && is_array($snapshot[$sectionsKey])) {
                $snapshot[$sectionsKey] = $this->renameKey($snapshot[$sectionsKey], $from, $to);
            }
        }

        return $this->encodeJson($snapshot);
    }

    private function replaceMergeTags(mixed $value, string $from, string $to): mixed
    {
        if (is_string($value)) {
            return str_replace("conditional.{$from}", "conditional.{$to}", $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->replaceMergeTags($nestedValue, $from, $to);
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function renameKey(array $value, string $from, string $to): array
    {
        if (! array_key_exists($from, $value)) {
            return $value;
        }

        if (! array_key_exists($to, $value)) {
            $value[$to] = $value[$from];
        } elseif (is_array($value[$from]) && is_array($value[$to])) {
            $value[$to] = array_replace($value[$from], $value[$to]);
        }

        unset($value[$from]);

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(mixed $json): ?array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json)) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
};
