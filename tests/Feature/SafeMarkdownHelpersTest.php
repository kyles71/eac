<?php

declare(strict_types=1);

use Illuminate\Support\HtmlString;

it('renders reusable block and inline Markdown as safe HTML', function (): void {
    $block = safe_markdown("Use **strong text**, `code`, and [a link](https://example.com).\n\nA second paragraph.");
    $inline = safe_inline_markdown('Use **strong text** and `code`.');

    expect($block)->toBeInstanceOf(HtmlString::class)
        ->and($block->toHtml())->toContain('<p>Use <strong>strong text</strong>, <code>code</code>, and <a href="https://example.com">a link</a>.</p>')
        ->and($block->toHtml())->toContain('<p>A second paragraph.</p>')
        ->and($inline)->toBeInstanceOf(HtmlString::class)
        ->and($inline->toHtml())->toContain('<strong>strong text</strong>')
        ->and($inline->toHtml())->not->toContain('<p>');
});

it('strips raw HTML and unsafe Markdown links', function (): void {
    $html = safe_markdown('<script>alert("unsafe")</script> [unsafe](javascript:alert("unsafe"))');

    expect($html->toHtml())
        ->not->toContain('<script')
        ->not->toContain('javascript:');
});
