<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateTemplate(addCostumeSection: true);
    }

    public function down(): void
    {
        $this->updateTemplate(addCostumeSection: false);
    }

    private function updateTemplate(bool $addCostumeSection): void
    {
        $table = (string) config('fin-mail.table_names.templates', 'email_templates');
        $template = DB::table($table)
            ->where('key', 'order-receipt')
            ->first(['id', 'body', 'conditional_sections']);

        if ($template === null) {
            return;
        }

        $body = $this->decode($template->body);
        $sections = $this->decode($template->conditional_sections) ?? [];

        if ($body === null) {
            return;
        }

        foreach ($body as $locale => $content) {
            if (! is_string($content)) {
                continue;
            }

            if ($addCostumeSection && ! str_contains($content, '{{ conditional.costume }}')) {
                $body[$locale] = str_contains($content, '{{ conditional.gear }}')
                    ? str_replace(
                        '{{ conditional.gear }}',
                        "{{ conditional.costume }}\n{{ conditional.gear }}",
                        $content,
                    )
                    : $content."\n{{ conditional.costume }}";
            }

            if (! $addCostumeSection) {
                $body[$locale] = str_replace("{{ conditional.costume }}\n", '', $content);
                $body[$locale] = str_replace('{{ conditional.costume }}', '', $body[$locale]);
            }
        }

        if ($addCostumeSection && ! array_key_exists('costume', $sections)) {
            $sections['costume'] = [
                'en' => '<p>We will share any costume distribution or pickup details separately.</p>',
            ];
        }

        if (! $addCostumeSection) {
            unset($sections['costume']);
        }

        DB::table($table)
            ->where('id', $template->id)
            ->update([
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
                'conditional_sections' => json_encode($sections, JSON_THROW_ON_ERROR),
            ]);
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
};
