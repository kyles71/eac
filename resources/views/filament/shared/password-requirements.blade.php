@php
    $statePath = $schemaComponent->getStatePath();
    $hasPasswordValidationError = $errors->has($statePath);
    $hasCompromisedPasswordError = collect($errors->get($statePath))
        ->contains(fn (string $message): bool => str_contains($message, 'appeared in a data leak'));
@endphp

<div
    data-password-requirements
    x-data="{
        password: '',
        hasBeenBlurred: @js($hasPasswordValidationError),
        hasCompromisedPasswordError: @js($hasCompromisedPasswordError),
        passwordInput: null,
        passwordBlurHandler: null,
        passwordInputHandler: null,
        init() {
            this.passwordInput = this.$root.closest('[data-field-wrapper]')?.querySelector('input')
            this.password = this.passwordInput?.value ?? ''
            this.passwordInputHandler = (event) => {
                this.password = event.target.value ?? ''
                this.hasCompromisedPasswordError = false
            }
            this.passwordBlurHandler = () => {
                this.hasBeenBlurred = true
            }
            this.passwordInput?.addEventListener('input', this.passwordInputHandler)
            this.passwordInput?.addEventListener('blur', this.passwordBlurHandler)
        },
    }"
    class="mt-2 text-sm"
    aria-live="polite"
>
    <p class="font-medium text-gray-950 dark:text-white">Password requirements:</p>

    <ul class="mt-1 space-y-1">
        <li
            data-password-requirement="minimum-length"
            x-bind:class="password.length >= {{ $minimumLength }}
                ? 'text-success-600 dark:text-success-400'
                : hasBeenBlurred
                    ? 'text-danger-600 dark:text-danger-400'
                    : 'text-gray-500 dark:text-gray-400'"
            class="flex items-center gap-1.5"
        >
            <span
                aria-hidden="true"
                x-text="password.length >= {{ $minimumLength }} ? '✓' : hasBeenBlurred ? '✕' : '•'"
            ></span>
            <span>At least {{ $minimumLength }} characters</span>
        </li>

        <li
            data-password-requirement="maximum-length"
            x-cloak
            x-show="password.length > {{ $maximumLength }}"
            class="flex items-center gap-1.5 text-danger-600 dark:text-danger-400"
        >
            <span aria-hidden="true">✕</span>
            <span>No more than {{ $maximumLength }} characters</span>
        </li>

        <li
            data-password-requirement="uncompromised"
            x-cloak
            x-show="hasCompromisedPasswordError"
            class="flex items-center gap-1.5 text-danger-600 dark:text-danger-400"
        >
            <span aria-hidden="true">✕</span>
            <span>Not found in a known data breach</span>
        </li>
    </ul>
</div>
