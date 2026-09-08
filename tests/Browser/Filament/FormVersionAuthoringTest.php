<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Forms\FormResource;
use App\Models\Form;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withVite();
    Filament::setCurrentPanel('admin');
});

it('renders the responsive Designer V2 workspace and temporary sidebar collapse', function (): void {
    $form = Form::factory()->create();
    $version = $form->createDraftVersion(schema: [
        [
            'type' => 'text',
            'data' => [
                'key' => (string) Str::uuid(),
                'content' => '<p><strong>Rich preview instructions</strong></p>',
            ],
        ],
        [
            'type' => 'short_text',
            'data' => [
                'key' => (string) Str::uuid(),
                'label' => 'Original preview question',
                'required' => false,
                'column_span' => 1,
            ],
        ],
    ]);
    $url = FormResource::getUrl('edit-version', [
        'record' => $form,
        'version' => $version,
    ], panel: 'admin');

    $desktop = visit($url)
        ->on()
        ->desktop()
        ->assertSee('Form canvas')
        ->assertSee('Inspector')
        ->assertSee('Rich preview instructions')
        ->assertSee('Original preview question')
        ->assertNoJavaScriptErrors();

    expect($desktop->script(<<<'JS'
        () => {
            const palette = document.querySelector('[data-designer-palette]').getBoundingClientRect()
            const canvas = document.querySelector('[data-designer-canvas]').getBoundingClientRect()
            const inspector = document.querySelector('[data-designer-inspector]').getBoundingClientRect()

            return {
                sameRow: Math.abs(palette.top - canvas.top) < 20 && Math.abs(canvas.top - inspector.top) < 20,
                canvasIsWider: canvas.width > palette.width && canvas.width > inspector.width,
            }
        }
        JS))->toBe([
        'sameRow' => true,
        'canvasIsWider' => true,
    ])
        ->and($desktop->script('() => window.Alpine.store("sidebar").isOpenDesktop'))->toBeFalse();

    $picker = $desktop->script(<<<'JS'
        () => {
            const items = Array.from(document.querySelectorAll('[data-designer-palette] button[draggable="true"]'))

            return {
                labels: items.map((element) => element.innerText.trim()),
                emergencyContactsHelp: items.find((element) => element.innerText.trim() === 'Emergency Contacts')?.title,
                width: Math.round(document.querySelector('[data-designer-palette]').getBoundingClientRect().width),
            }
        }
        JS);

    expect($picker['labels'])->toBe([
        'Section',
        'Instructions',
        'Assigned Subject',
        'Short Text',
        'Long Text',
        'Email',
        'Phone Number',
        'Number',
        'Date',
        'Consent Checkbox',
        'Toggle',
        'Yes / No Choice',
        'Select',
        'Radio',
        'Emergency Contacts',
    ])
        ->and($picker['emergencyContactsHelp'])->toContain('one or more emergency contacts')
        ->and($picker['width'])->toBeLessThanOrEqual(300);

    $desktop->script(<<<'JS'
        async () => {
            const item = Array.from(document.querySelectorAll('[data-designer-node]'))
                .find((element) => element.textContent.includes('Original preview question'))

            item.click()

            await new Promise((resolve) => setTimeout(resolve, 300))
            const input = Array.from(document.querySelectorAll('[data-designer-inspector] input'))
                .find((element) => ['Original preview question', 'Updated preview question'].includes(element.value))
            const valueSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set

            if (input && input.value !== 'Updated preview question') {
                input.focus()
                valueSetter.call(input, 'Updated preview question')
                input.dispatchEvent(new Event('input', { bubbles: true }))
                input.dispatchEvent(new Event('change', { bubbles: true }))
                input.blur()
            }

            await new Promise((resolve) => setTimeout(resolve, 1000))

            return true
        }
        JS);

    $desktop
        ->assertSee('Updated preview question')
        ->click('Preview')
        ->assertSee('Rich preview instructions')
        ->assertSee('Updated preview question')
        ->click('Save changes')
        ->click('View Form')
        ->assertSee($form->name);

    expect($desktop->script('() => window.Alpine.store("sidebar").isOpenDesktop'))->toBeTrue();

    $mobile = visit($url)
        ->on()
        ->mobile()
        ->wait(1)
        ->assertSee('Form canvas')
        ->assertSee('Rich preview instructions')
        ->assertNoJavaScriptErrors();

    expect($mobile->script(<<<'JS'
        () => {
            const canvas = document.querySelector('[data-designer-canvas]').getBoundingClientRect()

            return canvas.width <= window.innerWidth && canvas.left >= 0
        }
        JS))->toBeTrue();

    expect($mobile->script(<<<'JS'
        () => {
            const toolbar = document.querySelector('.ffb-designer-toolbar').getBoundingClientRect()
            const tabs = document.querySelector('.ffb-designer-toolbar-start').getBoundingClientRect()
            const drawerControls = document.querySelector('.ffb-designer-toolbar-center').getBoundingClientRect()
            const actions = document.querySelector('.ffb-designer-toolbar-actions').getBoundingClientRect()
            const visibleControls = Array.from(document.querySelectorAll('.ffb-designer-toolbar button'))
                .filter((button) => button.offsetParent !== null)

            return {
                firstRowDoesNotOverlap: tabs.right <= actions.left + 1,
                drawerControlsOnSecondRow: drawerControls.top >= Math.max(tabs.bottom, actions.bottom) - 1,
                toolbarContainsControls: visibleControls.every((control) => {
                    const bounds = control.getBoundingClientRect()

                    return bounds.left >= toolbar.left - 1
                        && bounds.right <= toolbar.right + 1
                        && bounds.top >= toolbar.top - 1
                        && bounds.bottom <= toolbar.bottom + 1
                }),
                toolbarFitsViewport: toolbar.left >= 0 && toolbar.right <= window.innerWidth,
            }
        }
        JS))->toBe([
        'firstRowDoesNotOverlap' => true,
        'drawerControlsOnSecondRow' => true,
        'toolbarContainsControls' => true,
        'toolbarFitsViewport' => true,
    ]);

    $drawer = $mobile->script(<<<'JS'
        async () => {
            const button = Array.from(document.querySelectorAll('button'))
                .find((element) => element.offsetParent !== null && element.innerText.trim() === 'Fields')

            button.click()
            await new Promise((resolve) => setTimeout(resolve, 200))

            const palette = document.querySelector('[data-designer-palette]')
            const bounds = palette.getBoundingClientRect()

            return {
                className: palette.className,
                display: getComputedStyle(palette).display,
                visible: bounds.width > 0 && bounds.height > 0,
            }
        }
        JS);

    expect($drawer['className'])->toContain('ffb-mobile-drawer-open')
        ->and($drawer['display'])->toBe('block')
        ->and($drawer['visible'])->toBeTrue();

    $mobile->script(<<<'JS'
        async () => {
            document.querySelector('[aria-label="Close field palette"]').click()
            await new Promise((resolve) => setTimeout(resolve, 200))

            Array.from(document.querySelectorAll('button'))
                .find((element) => element.innerText.trim() === 'Preview')
                .click()

            await new Promise((resolve) => setTimeout(resolve, 500))
        }
        JS);

    $mobile
        ->assertSee('Updated preview question')
        ->assertNoJavaScriptErrors();
});
