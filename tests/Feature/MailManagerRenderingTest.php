<?php

declare(strict_types=1);

use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Enums\LayoutMode;
use Kyle\FilamentMailManager\Exceptions\DuplicateEmailType;
use Kyle\FilamentMailManager\Exceptions\InvalidEmailTemplate;
use Kyle\FilamentMailManager\MailManager;
use Kyle\FilamentMailManager\Mason\BrickCollection;
use Kyle\FilamentMailManager\Mason\Bricks\EmailBodyBrick;
use Kyle\FilamentMailManager\Mason\Bricks\TextBrick;
use Kyle\FilamentMailManager\Models\MailLayout;
use Kyle\FilamentMailManager\Models\ManagedEmailTemplate;
use Kyle\FilamentMailManager\PreviewManager;
use Kyle\FilamentMailManager\Rendering\LayoutValidator;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;
use Kyle\FilamentMailManager\Settings\LayoutSettings;
use Kyle\FilamentMailManager\VersionComparator;

it('rejects duplicate registered email type keys', function (): void {
    $definition = new EmailTypeDefinition(
        key: 'test',
        names: ['en' => 'Test'],
        description: 'Test',
        category: 'test',
        subjects: ['en' => 'Test'],
        bodies: ['en' => '<p>Test</p>'],
    );
    $registry = new EmailTypeRegistry;

    $registry->register([$definition]);
    $registry->register([$definition]);
})->throws(DuplicateEmailType::class);

it('escapes ordinary tokens and renders trusted system slots', function (): void {
    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'user-password-reset',
        tokens: [
            'app.name' => 'EAC',
            'user.first_name' => '<script>alert("x")</script>',
        ],
        slots: [
            'action' => '<p><a href="https://example.com/reset">Reset Password</a></p>',
        ],
    );

    expect($rendered->subject)->toBe('Reset your EAC password')
        ->and($rendered->html)
        ->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;')
        ->toContain('<a href="https://example.com/reset">Reset Password</a>')
        ->not->toContain('<script>');
});

it('requires declared system slot content', function (): void {
    app(MailManager::class)->render(
        emailTypeKey: 'user-password-reset',
        tokens: [
            'app.name' => 'EAC',
            'user.first_name' => 'Kyle',
        ],
    );
})->throws(InvalidEmailTemplate::class, 'Required system slot [action] has no runtime content.');

it('keeps system slots inside valid rich editor paragraphs', function (): void {
    $registry = app(EmailTypeRegistry::class);

    expect($registry->get('user-password-reset')->body('en'))->toContain('<p>{{ slot.action }}</p>')
        ->and($registry->get('user-verify-email')->body('en'))->toContain('<p>{{ slot.action }}</p>');
});

it('requires exactly one email body layout block', function (): void {
    app(LayoutValidator::class)->validate([]);
})->throws(InvalidEmailTemplate::class, 'exactly one Email Body block');

it('resolves inherited layouts and keeps immutable versions when overrides change', function (): void {
    $layout = MailLayout::create([
        'name' => 'Default',
        'content' => BrickCollection::defaultContent(),
    ]);
    $layout->saveVersion();

    $settings = app(LayoutSettings::class);
    $settings->default_layout_id = $layout->id;
    $settings->save();

    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'user-password-reset',
        tokens: [
            'app.name' => 'EAC',
            'user.first_name' => 'Kyle',
        ],
        slots: [
            'action' => '<p>Reset link</p>',
        ],
    );

    expect($rendered->html)->toContain('Reset link')
        ->and($layout->versions()->count())->toBe(1);

    $repository = app(ManagedTemplateRepository::class);
    $repository->saveOverride('user-password-reset', [
        'subject' => 'Changed subject',
        'body' => '<p>Changed for {{ user.first_name }}</p>{{ slot.action }}',
        'layout_mode' => LayoutMode::None,
    ]);
    $repository->saveOverride('user-password-reset', [
        'subject' => 'Changed again',
        'body' => '<p>Again for {{ user.first_name }}</p>{{ slot.action }}',
        'layout_mode' => LayoutMode::Inherited,
    ]);

    $template = ManagedEmailTemplate::where('key', 'user-password-reset')->firstOrFail();

    expect($template->versions)->toHaveCount(2)
        ->and($template->versions->first()->manager_snapshot)->toHaveKeys([
            'subject',
            'body',
            'is_active',
            'layout_mode',
            'mail_layout_id',
        ]);

    $repository->revert('user-password-reset');

    expect($repository->effective('user-password-reset')->isCustomized)->toBeFalse();
});

it('serves signed previews only to their owner', function (): void {
    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'user-password-reset',
        tokens: [
            'app.name' => 'EAC',
            'user.first_name' => 'Kyle',
        ],
        slots: [
            'action' => '<p>Reset link</p>',
        ],
    );
    $url = app(PreviewManager::class)->temporaryUrl($rendered);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Security-Policy')
        ->assertSee('Reset link', false);

    $this->actingAs(App\Models\User::factory()->create())
        ->get($url)
        ->assertNotFound();
});

it('restores email versions as a new immutable version and compares their changes', function (): void {
    $repository = app(ManagedTemplateRepository::class);
    $repository->saveOverride('user-password-reset', [
        'subject' => 'First subject',
        'body' => '<p>First body</p>{{ slot.action }}',
        'layout_mode' => LayoutMode::None,
    ]);
    $repository->saveOverride('user-password-reset', [
        'subject' => 'Second subject',
        'body' => '<p>Second body</p>{{ slot.action }}',
        'layout_mode' => LayoutMode::Inherited,
        'is_active' => false,
    ]);

    $template = ManagedEmailTemplate::where('key', 'user-password-reset')->firstOrFail();
    $versions = $template->versions()->orderBy('version')->get();
    $changes = app(VersionComparator::class)->compare($versions[0], $versions[1]);

    expect($changes)
        ->active_changed->toBeTrue()
        ->subject_changed->toBeTrue()
        ->body_changed->toBeTrue()
        ->layout_changed->toBeTrue()
        ->and($repository->restoreVersion('user-password-reset', 1))->toBeTrue()
        ->and($repository->effective('user-password-reset')->subject)->toBe('First subject')
        ->and($repository->effective('user-password-reset')->isActive)->toBeTrue()
        ->and($template->versions()->count())->toBe(3);
});

it('restores layout content as a new immutable version', function (): void {
    $layout = MailLayout::create([
        'name' => 'First layout',
        'content' => BrickCollection::defaultContent(),
    ]);
    $layout->saveVersion();
    $layout->update([
        'name' => 'Second layout',
    ]);
    $layout->saveVersion();

    expect($layout->restoreVersion(1))->toBeTrue()
        ->and($layout->refresh()->name)->toBe('First layout')
        ->and($layout->versions()->count())->toBe(3);
});

it('uses the handcrafted email type layout for freeform emails', function (): void {
    $layout = MailLayout::create([
        'name' => 'Handcrafted layout',
        'content' => [
            [
                'type' => 'masonBrick',
                'attrs' => [
                    'id' => TextBrick::getId(),
                    'config' => ['content' => '<p>Managed handcrafted header</p>'],
                ],
            ],
            [
                'type' => 'masonBrick',
                'attrs' => [
                    'id' => EmailBodyBrick::getId(),
                    'config' => [],
                ],
            ],
        ],
    ]);
    app(ManagedTemplateRepository::class)->saveOverride('handcrafted', [
        'layout_mode' => LayoutMode::Specific,
        'mail_layout_id' => $layout->id,
    ]);

    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'handcrafted',
        tokens: [
            'email.subject' => 'Class update',
        ],
        slots: [
            'content' => '<p>See you soon.</p>',
        ],
    );

    expect($rendered->subject)->toBe('Class update')
        ->and($rendered->html)->toContain('Managed handcrafted header')
        ->toContain('See you soon.');
});
