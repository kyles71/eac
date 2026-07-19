<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ManagedBannerRenderLocation: string implements HasLabel
{
    case ContentStart = 'panels::content.start';
    case ContentEnd = 'panels::content.end';
    case PageStart = 'panels::page.start';
    case PageEnd = 'panels::page.end';
    case PageHeaderWidgetsBefore = 'panels::page.header-widgets.before';
    case PageHeaderWidgetsAfter = 'panels::page.header-widgets.after';
    case PageFooterWidgetsBefore = 'panels::page.footer-widgets.before';
    case PageFooterWidgetsAfter = 'panels::page.footer-widgets.after';
    case SidebarNavStart = 'panels::sidebar.nav.start';
    case SidebarNavEnd = 'panels::sidebar.nav.end';
    case TopbarBefore = 'panels::topbar.before';

    public function getLabel(): string
    {
        return match ($this) {
            self::ContentStart => 'Content start',
            self::ContentEnd => 'Content end',
            self::PageStart => 'Page start',
            self::PageEnd => 'Page end',
            self::PageHeaderWidgetsBefore => 'Before header widgets',
            self::PageHeaderWidgetsAfter => 'After header widgets',
            self::PageFooterWidgetsBefore => 'Before footer widgets',
            self::PageFooterWidgetsAfter => 'After footer widgets',
            self::SidebarNavStart => 'Sidebar nav start',
            self::SidebarNavEnd => 'Sidebar nav end',
            self::TopbarBefore => 'Above topbar',
        };
    }
}
