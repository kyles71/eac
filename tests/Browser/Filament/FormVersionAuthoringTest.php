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

it('renders responsive authoring columns with a live preview and temporary sidebar collapse', function (): void {
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
        ->assertSee('Live preview')
        ->assertSee('Rich preview instructions')
        ->assertSee('Short Text: Original preview question')
        ->assertSee('Original preview question')
        ->assertDontSee('Complete this block to preview it.')
        ->assertNoJavaScriptErrors();

    expect($desktop->script(<<<'JS'
        () => {
            const editor = document.querySelector('[data-form-version-editor="editor"]').getBoundingClientRect()
            const preview = document.querySelector('[data-form-version-editor="preview"]').getBoundingClientRect()

            return {
                sameRow: Math.abs(editor.top - preview.top) < 20,
                editorIsWider: editor.width > preview.width,
            }
        }
        JS))->toBe([
        'sameRow' => true,
        'editorIsWider' => true,
    ])
        ->and($desktop->script('() => window.Alpine.store("sidebar").isOpenDesktop'))->toBeFalse();

    $desktop->click('Add to form');

    $picker = $desktop->script(<<<'JS'
        async () => {
            await new Promise((resolve) => setTimeout(resolve, 100))

            const panel = Array.from(document.querySelectorAll('.fi-fo-builder-block-picker .fi-dropdown-panel'))
                .find((element) => element.offsetParent !== null)
            const picker = panel.closest('.fi-fo-builder-block-picker')
            const result = {
                labels: Array.from(panel.querySelectorAll('.fi-dropdown-list-item-label'))
                    .map((element) => element.innerText.trim()),
                tooltipCount: panel.querySelectorAll('[x-tooltip]').length,
                tooltipLabelDisplay: getComputedStyle(panel.querySelector('[data-form-block-picker-label]')).display,
                width: Math.round(panel.getBoundingClientRect().width),
            }

            picker.querySelector('.fi-dropdown-trigger button').click()

            return result
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
        'Select',
        'Radio',
        'Yes / No Choice',
        'Consent Checkbox',
        'Toggle',
        'Emergency Contacts',
    ])
        ->and($picker['tooltipCount'])->toBe(5)
        ->and($picker['tooltipLabelDisplay'])->toBe('inline-flex')
        ->and($picker['width'])->toBeLessThanOrEqual(680);

    $desktop->script(<<<'JS'
        async () => {
            const item = Array.from(document.querySelectorAll('.fi-fo-builder-item'))
                .find((element) => element.textContent.includes('Original preview question'))

            item.querySelector('.fi-fo-builder-item-preview-edit-overlay').click()

            await new Promise((resolve) => setTimeout(resolve, 300))
        }
        JS);

    $desktop->script(<<<'JS'
        async () => {
            const input = Array.from(document.querySelectorAll('input'))
                .find((element) => element.value === 'Original preview question')
            const valueSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set

            input.focus()
            valueSetter.call(input, 'Updated preview question')
            input.dispatchEvent(new Event('input', { bubbles: true }))
            input.dispatchEvent(new Event('change', { bubbles: true }))
            input.blur()

            const modal = Array.from(document.querySelectorAll('.fi-modal'))
                .find((element) => element.offsetParent !== null)
            const saveButton = Array.from(modal.querySelectorAll('button'))
                .find((element) => element.innerText.trim() === 'Save')

            saveButton.click()

            await new Promise((resolve) => setTimeout(resolve, 1000))
        }
        JS);

    $desktop
        ->assertSee('Updated preview question')
        ->click('View Form')
        ->assertSee($form->name);

    expect($desktop->script('() => window.Alpine.store("sidebar").isOpenDesktop'))->toBeTrue();

    $mobile = visit($url)
        ->on()
        ->mobile()
        ->assertSee('Live preview')
        ->assertSee('Rich preview instructions')
        ->assertDontSee('Complete this block to preview it.')
        ->assertNoJavaScriptErrors();

    expect($mobile->script(<<<'JS'
        () => {
            const editor = document.querySelector('[data-form-version-editor="editor"]').getBoundingClientRect()
            const preview = document.querySelector('[data-form-version-editor="preview"]').getBoundingClientRect()

            return preview.top >= (editor.bottom - 2)
        }
        JS))->toBeTrue();
});
