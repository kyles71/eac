<?php

declare(strict_types=1);

use App\Filament\Schemas\SendEmail;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

it('returns a schema instance', function (): void {
    $schema = Mockery::mock(Schema::class);

    $schema->shouldReceive('components')
        ->once()
        ->withArgs(function (array $components): bool {
            return count($components) === 3;
        })
        ->andReturnSelf();

    $result = SendEmail::configure($schema);

    expect($result)->toBeInstanceOf(Schema::class);
});

it('configures schema with to, subject, and body components', function (): void {
    $schema = Mockery::mock(Schema::class);

    $schema->shouldReceive('components')
        ->once()
        ->withArgs(function (array $components): bool {
            expect($components[0])->toBeInstanceOf(TagsInput::class);
            expect($components[1])->toBeInstanceOf(TextInput::class);
            expect($components[2])->toBeInstanceOf(Textarea::class);

            return true;
        })
        ->andReturnSelf();

    SendEmail::configure($schema);
});

it('pre-populates to field from an array', function (): void {
    $emails = ['test@example.com', 'admin@example.com'];

    $schema = Mockery::mock(Schema::class);

    $schema->shouldReceive('components')
        ->once()
        ->withArgs(function (array $components) use ($emails): bool {
            /** @var TagsInput $toField */
            $toField = $components[0];
            expect($toField)->toBeInstanceOf(TagsInput::class);
            expect($toField->getDefaultState())->toBe($emails);

            return true;
        })
        ->andReturnSelf();

    SendEmail::configure($schema, $emails);
});

it('pre-populates to field from a closure', function (): void {
    $emails = ['closure@example.com', 'dynamic@example.com'];

    $schema = Mockery::mock(Schema::class);

    $schema->shouldReceive('components')
        ->once()
        ->withArgs(function (array $components) use ($emails): bool {
            /** @var TagsInput $toField */
            $toField = $components[0];
            expect($toField)->toBeInstanceOf(TagsInput::class);
            expect($toField->getDefaultState())->toBe($emails);

            return true;
        })
        ->andReturnSelf();

    SendEmail::configure($schema, fn (): array => $emails);
});

it('defaults to empty array when no to addresses are provided', function (): void {
    $schema = Mockery::mock(Schema::class);

    $schema->shouldReceive('components')
        ->once()
        ->withArgs(function (array $components): bool {
            /** @var TagsInput $toField */
            $toField = $components[0];
            expect($toField)->toBeInstanceOf(TagsInput::class);
            expect($toField->getDefaultState())->toBe([]);

            return true;
        })
        ->andReturnSelf();

    SendEmail::configure($schema);
});
