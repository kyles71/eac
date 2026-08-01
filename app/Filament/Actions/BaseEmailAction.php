<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\Student;
use App\Models\User;
use App\Support\HandcraftedEmailRecipients;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;

abstract class BaseEmailAction extends Action
{
    /**
     * @var array<int, Student|User|string>|Student|User|string|Closure
     */
    protected array|Student|User|string|Closure $defaultTo = [];

    /**
     * @param  array<int, Student|User|string>|Student|User|string|Closure  $to
     */
    final public function to(array|Student|User|string|Closure $to): static
    {
        $this->defaultTo = $to;

        return $this;
    }

    protected function recipientSelect(): Select
    {
        return Select::make('to')
            ->label('To')
            ->multiple()
            ->searchable()
            ->searchDebounce(500)
            ->searchPrompt('Type at least 3 characters to search students or teachers, or enter a complete email address.')
            ->searchingMessage('Searching recipients...')
            ->noSearchResultsMessage('No matching students, teachers, or email address.')
            ->getSearchResultsUsing(
                fn (string $search): array => app(HandcraftedEmailRecipients::class)->search(
                    $search,
                    $this->authenticatedUser(),
                )
            )
            ->getOptionLabelsUsing(
                fn (array $values): array => app(HandcraftedEmailRecipients::class)->labels(
                    $values,
                    $this->authenticatedUser(),
                )
            )
            ->default(app(HandcraftedEmailRecipients::class)->defaultValues($this->getDefaultTo()))
            ->placeholder('Add recipients')
            ->required();
    }

    /**
     * @return list<string>
     */
    protected function resolveRecipients(mixed $values): array
    {
        return app(HandcraftedEmailRecipients::class)->resolve(
            $values,
            $this->authenticatedUser(),
        );
    }

    /**
     * @return array<int, Student|User|string>
     */
    protected function getDefaultTo(): array
    {
        $recipients = $this->evaluate($this->defaultTo);

        return is_array($recipients) ? $recipients : [$recipients];
    }

    protected function authenticatedUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
