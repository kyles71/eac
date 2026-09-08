<?php

declare(strict_types=1);

namespace App\Forms\Migration;

use App\Forms\Eac\EacFormContentProvider;
use RuntimeException;

final readonly class LegacyStudentWaiverContract
{
    private const string ExpectedHash = 'fb39de33040f862c5f5363234fa1c7761fb0fff555f135c7b843839d3bc4e725';

    /** @param array<int, array<string, mixed>> $schema */
    public function assertMatches(array $schema): void
    {
        $actual = $this->hash($schema);

        if (! hash_equals(self::ExpectedHash, $actual)) {
            throw new RuntimeException("The dynamic student-waiver contract does not match the approved legacy contract ({$actual}).");
        }
    }

    /** @param array<int, array<string, mixed>> $schema */
    public function hash(array $schema): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($this->normalizeBlocks($schema)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');

            if ($type === 'subject') {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $entry = [
                'type' => $type,
                'data' => collect($data)
                    ->only([
                        'key', 'heading', 'columns', 'label', 'mapping', 'help', 'required',
                        'default_today', 'options', 'help_is_html', 'help_position', 'true_label',
                        'false_label', 'column_span', 'min_items', 'default_items', 'content', 'is_html',
                    ])
                    ->all(),
            ];

            if (is_string($data['help_reference'] ?? null)) {
                $entry['data']['help_reference'] = str_starts_with(
                    $data['help_reference'],
                    EacFormContentProvider::HealthSafetyPolicyVersionPrefix,
                ) ? 'initial-health-safety-policy' : $data['help_reference'];
            }

            if (is_string($data['text_message_policy_reference'] ?? null)) {
                $entry['data']['text_message_policy_reference'] = str_starts_with(
                    $data['text_message_policy_reference'],
                    EacFormContentProvider::TextMessageUpdatesPolicyVersionPrefix,
                ) ? 'initial-text-message-updates-policy' : $data['text_message_policy_reference'];
            }

            if (is_array($data['components'] ?? null)) {
                $entry['data']['components'] = $this->normalizeBlocks($data['components']);
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(
            fn (mixed $item): mixed => $this->canonicalize($item),
            $value,
        );
    }
}
