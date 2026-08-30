<?php

declare(strict_types=1);

namespace App\Filament\Tables\Columns;

use App\Enums\AttendanceStatus;
use Filament\Support\Components\Contracts\HasEmbeddedView;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\Concerns\CanBeValidated;
use Filament\Tables\Columns\Concerns\CanUpdateState;
use Filament\Tables\Columns\Contracts\Editable;
use Filament\Tables\Table;
use Illuminate\Support\Js;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\ComponentAttributeBag;

final class AttendanceRadioColumn extends Column implements Editable, HasEmbeddedView
{
    use CanBeValidated;
    use CanUpdateState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disabledClick();
        $this->rules(fn (): array => [new Enum(AttendanceStatus::class)]);
    }

    public function toEmbeddedHtml(): string
    {
        $isDisabled = $this->isDisabled();
        $name = $this->getName();
        $recordKey = $this->getRecordKey();
        $state = $this->getState();
        $state = $state instanceof AttendanceStatus ? $state->value : $state;
        $state = is_string($state) ? $state : '';

        $attributes = $this->getExtraAttributeBag()
            ->merge([
                'x-data' => '{
                    error: null,
                    isLoading: false,
                    state: '.Js::from($state).',
                    async updateAttendanceStatus() {
                        if (this.isLoading) {
                            return
                        }

                        this.error = null
                        this.isLoading = true

                        const result = await $wire.updateTableColumnState(
                            '.Js::from($name).',
                            '.Js::from($recordKey).',
                            this.state,
                        )

                        if (result?.error) {
                            this.error = result.error
                            this.state = this.$refs.serverState.value
                        } else {
                            this.state = result ?? \'\'
                            this.$refs.serverState.value = this.state
                        }

                        this.isLoading = false
                    },
                }',
                'x-on:click.stop' => '',
                'aria-label' => $this->getLabel(),
                'role' => 'radiogroup',
            ], escape: false)
            ->class([
                'fi-fo-radio',
                'fi-inline',
            ]);

        $inputAttributes = (new ComponentAttributeBag)
            ->merge([
                'disabled' => $isDisabled,
                'wire:loading.attr' => 'disabled',
                'wire:target' => implode(',', Table::LOADING_TARGETS),
                'x-bind:disabled' => $isDisabled ? null : 'isLoading',
                'x-model' => 'state',
                'x-on:change' => 'updateAttendanceStatus()',
            ], escape: false)
            ->class([
                'fi-radio-input',
            ]);

        $options = [
            '' => 'Not recorded',
            ...collect(AttendanceStatus::cases())
                ->mapWithKeys(fn (AttendanceStatus $status): array => [$status->value => $status->getLabel()])
                ->all(),
        ];

        ob_start(); ?>

        <div <?= $attributes->toHtml() ?>>
            <input type="hidden" value="<?= e($state) ?>" x-ref="serverState" />

            <?php foreach ($options as $value => $label) { ?>
                <label class="fi-fo-radio-label">
                    <input
                        type="radio"
                        name="<?= e("{$name}-{$recordKey}") ?>"
                        value="<?= e($value) ?>"
                        <?= $inputAttributes->toHtml() ?>
                    />

                    <span class="fi-fo-radio-label-text">
                        <span><?= e($label) ?></span>
                    </span>
                </label>
            <?php } ?>

            <p
                x-cloak
                x-show="error"
                x-text="error"
                class="text-sm text-danger-600 dark:text-danger-400"
            ></p>
        </div>

        <?php return ob_get_clean();
    }
}
