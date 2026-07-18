<?php

declare(strict_types=1);

namespace App\Forms\Eac;

use App\Models\LegalDocumentVersion;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Kyle\FilamentFormBuilder\Content\FormContentReference;
use Kyle\FilamentFormBuilder\Contracts\DynamicFormContentProvider;
use Kyle\FilamentFormBuilder\Contracts\FormContentProvider;
use Kyle\FilamentFormBuilder\Enums\FormContentSlot;
use Kyle\FilamentFormBuilder\Models\FormAssignment;
use Kyle\FilamentFormBuilder\Models\FormVersion;

final readonly class EacFormContentProvider implements DynamicFormContentProvider, FormContentProvider
{
    public const string HealthSafetyPolicyVersionPrefix = 'eac.health-safety-policy-version:';

    public const string TextMessageUpdatesPolicyVersionPrefix = 'eac.text-message-updates-policy-version:';

    private const string LinkClasses = 'fi-link fi-size-sm fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-400';

    public static function healthSafetyPolicyReference(LegalDocumentVersion|int $version): string
    {
        $id = $version instanceof LegalDocumentVersion ? $version->getKey() : $version;

        return self::HealthSafetyPolicyVersionPrefix.$id;
    }

    public static function textMessageUpdatesPolicyReference(LegalDocumentVersion|int $version): string
    {
        $id = $version instanceof LegalDocumentVersion ? $version->getKey() : $version;

        return self::TextMessageUpdatesPolicyVersionPrefix.$id;
    }

    public function references(): array
    {
        return LegalDocumentVersion::query()
            ->whereNotNull('published_at')
            ->whereHas('document', fn (Builder $query): Builder => $query->whereIn('key', [
                HealthSafetyPolicy::KEY,
                TextMessageUpdatesPolicy::KEY,
            ]))
            ->with('document')
            ->orderByDesc('version')
            ->get()
            ->map(fn (LegalDocumentVersion $version): FormContentReference => $this->contentReference($version))
            ->all();
    }

    public function reference(string $reference): ?FormContentReference
    {
        if (! $this->supportsReference($reference)) {
            return null;
        }

        return $this->contentReference($this->legalDocumentVersion($reference));
    }

    public function resolve(
        string $reference,
        FormVersion $version,
        ?FormAssignment $assignment = null,
    ): string|Htmlable|null {
        if (! $this->supportsReference($reference)) {
            return null;
        }

        $isTextMessagePolicy = str_starts_with($reference, self::TextMessageUpdatesPolicyVersionPrefix);

        return $this->legalDocumentLink(
            $this->legalDocumentVersion($reference),
            $isTextMessagePolicy
                ? 'Click here to view our full Text Message Updates Policy'
                : 'View and print the EAC Health & Safety Policy',
        );
    }

    public function currentHealthSafetyPolicyReference(): string
    {
        $version = HealthSafetyPolicy::currentVersion();

        if (! $version instanceof LegalDocumentVersion) {
            throw new InvalidArgumentException('A published Health & Safety Policy version is required before publishing the student waiver.');
        }

        return self::healthSafetyPolicyReference($version);
    }

    public function currentTextMessageUpdatesPolicyReference(): string
    {
        $version = TextMessageUpdatesPolicy::currentVersion();

        if (! $version instanceof LegalDocumentVersion) {
            throw new InvalidArgumentException('A published Text Message Updates Policy version is required before publishing the student waiver.');
        }

        return self::textMessageUpdatesPolicyReference($version);
    }

    /** @return array<string, string> */
    public function textMessageUpdatesPolicyOptions(): array
    {
        return collect($this->references())
            ->filter(fn (FormContentReference $reference): bool => str_starts_with(
                $reference->key,
                self::TextMessageUpdatesPolicyVersionPrefix,
            ))
            ->mapWithKeys(fn (FormContentReference $reference): array => [$reference->key => $reference->label])
            ->all();
    }

    public function textMessageUpdatesHelp(string $reference): string|Htmlable
    {
        $helperText = 'Text message updates are only utilized for urgent updates, such as class cancellation due to weather conditions or a health/safety issue.';
        $link = $this->resolve($reference, new FormVersion);

        return $link === null
            ? $helperText
            : new HtmlString(e($helperText).' '.$link);
    }

    private function legalDocumentLink(?LegalDocumentVersion $version, string $label): ?HtmlString
    {
        if ($version === null) {
            return null;
        }

        return new HtmlString('<a class="'.self::LinkClasses.'" href="'.e(route('legal-documents.versions.show', $version)).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a>');
    }

    private function legalDocumentVersion(string $reference): LegalDocumentVersion
    {
        [$prefix, $documentKey, $documentLabel] = str_starts_with($reference, self::HealthSafetyPolicyVersionPrefix)
            ? [self::HealthSafetyPolicyVersionPrefix, HealthSafetyPolicy::KEY, 'Health & Safety Policy']
            : [self::TextMessageUpdatesPolicyVersionPrefix, TextMessageUpdatesPolicy::KEY, 'Text Message Updates Policy'];
        $id = mb_substr($reference, mb_strlen($prefix));

        if ($id === '' || ! ctype_digit($id)) {
            throw new InvalidArgumentException("The {$documentLabel} reference [{$reference}] is invalid.");
        }

        $version = LegalDocumentVersion::query()->with('document')->find((int) $id);

        if (! $version instanceof LegalDocumentVersion) {
            throw new InvalidArgumentException("The selected Health & Safety Policy version [{$id}] no longer exists.");
        }

        if ($version->published_at === null) {
            throw new InvalidArgumentException("The selected Health & Safety Policy version [{$id}] is not published.");
        }

        if ($version->document->key !== $documentKey) {
            throw new InvalidArgumentException("The selected legal document version [{$id}] does not belong to the {$documentLabel}.");
        }

        return $version;
    }

    private function contentReference(LegalDocumentVersion $version): FormContentReference
    {
        $isHealthSafetyPolicy = $version->document->key === HealthSafetyPolicy::KEY;

        return new FormContentReference(
            key: $isHealthSafetyPolicy
                ? self::healthSafetyPolicyReference($version)
                : self::textMessageUpdatesPolicyReference($version),
            label: ($isHealthSafetyPolicy ? 'EAC Health & Safety Policy' : 'Text Message Updates Policy')
                ." — {$version->versionLabel()}: {$version->title}",
            slots: [FormContentSlot::QuestionHelp],
        );
    }

    private function supportsReference(string $reference): bool
    {
        return str_starts_with($reference, self::HealthSafetyPolicyVersionPrefix)
            || str_starts_with($reference, self::TextMessageUpdatesPolicyVersionPrefix);
    }
}
