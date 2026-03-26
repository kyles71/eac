<?php

declare(strict_types=1);

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
