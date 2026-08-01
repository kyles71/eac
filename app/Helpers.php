<?php

declare(strict_types=1);

use Illuminate\Support\HtmlString;

/*
 * Here you can define your own helper functions.
 * Make sure to use the `function_exists` check to not declare the function twice.
 */

if (! function_exists('format_money')) {
    /**
     * Format cents as dollars.
     */
    function format_money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}

if (! function_exists('safe_markdown')) {
    /**
     * Render block Markdown as sanitized HTML.
     */
    function safe_markdown(string $markdown): HtmlString
    {
        return new HtmlString((string) str($markdown)
            ->markdown([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ])
            ->sanitizeHtml());
    }
}

if (! function_exists('safe_inline_markdown')) {
    /**
     * Render inline Markdown as sanitized HTML without a wrapping paragraph.
     */
    function safe_inline_markdown(string $markdown): HtmlString
    {
        return new HtmlString((string) str($markdown)
            ->inlineMarkdown([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ])
            ->sanitizeHtml());
    }
}
